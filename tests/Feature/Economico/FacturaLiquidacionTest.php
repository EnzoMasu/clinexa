<?php

/*
 * La factura de la liquidación a la aseguradora (docs/facturacion.md): la emisión válida (CRÉDITO, a nombre de la aseguradora con su
 * RUC, una línea por prestación con lo que paga la aseguradora y su IVA), la aseguradora sin RUC, la liquidación fuera de estado, la
 * doble emisión, el copago facturado aparte sin facturar dos veces la misma parte, la numeración sin saltos compartida con la contado,
 * el estado de cobro que sigue a los pagos (COBRADO y de vuelta a EMITIDO), la anulación con y sin pagos vigentes (solo con la
 * solicitud que aprueba Gerencia, sin caja), que nadie apruebe la propia, la liquidación que no se anula con factura vigente, la
 * trazabilidad entre factura, liquidación y prestaciones, los permisos, la auditoría y la marca de agua del PDF en una factura vieja y
 * en una nueva. Datos, precios y números inventados. Prefijo "facturaLiquidacion".
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Caja;
use App\Models\Clinica;
use App\Models\Consulta;
use App\Models\DetalleFactura;
use App\Models\Estado;
use App\Models\Factura;
use App\Models\LineaVisacion;
use App\Models\Liquidacion;
use App\Models\LogAuditoria;
use App\Models\MedioPago;
use App\Models\MotivoAnulacionFactura;
use App\Models\MovimientoCaja;
use App\Models\PagoAseguradora;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\ResponsablePago;
use App\Models\SolicitudAnulacionFactura;
use App\Models\Sucursal;
use App\Models\Timbrado;
use App\Models\User;
use App\Support\Convenios\CalcularCobertura;
use App\Support\Convenios\FirmaRecibida;
use App\Support\Convenios\GestionCoberturas;
use App\Support\Convenios\GestionConvenios;
use App\Support\Facturacion\AnularFactura;
use App\Support\Facturacion\EmitirFactura;
use App\Support\Facturacion\FacturarLiquidacion;
use App\Support\Facturacion\GestionTimbrados;
use App\Support\Facturacion\PdfFactura;
use App\Support\Liquidaciones\FlujoLiquidacion;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Ruc;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Database\Seeders\MotivosAnulacionFacturaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    hcEscenario(); // "hoy": martes 06/10/2026, 09:00 en Paraguay
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    (new MotivosAnulacionFacturaSeeder)->run();
    $this->consultaProc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->gerente = facturaLiquidacionUsuario(PerfilesPredefinidos::GERENCIA);
    $this->recepcion = facturaLiquidacionUsuario(PerfilesPredefinidos::RECEPCION);
    $this->facturacion = facturaLiquidacionUsuario(PerfilesPredefinidos::FACTURACION);
    $this->cajero = facturaLiquidacionUsuario(PerfilesPredefinidos::CAJA);
    $this->admin = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->sole()])->create();
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO, CalcularCobertura::HITO, EmitirFactura::HITO])
        ->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $this->consultaProc->id, null, 100000, Carbon::parse('2026-10-01'));

    $this->personaAseguradora = Persona::factory()->create(['tipo_persona' => 'JURIDICA', 'razon_social' => 'Seguros de Prueba S.A.', 'ruc' => Ruc::conDigito('80888001')]);
    $this->aseguradora = ResponsablePago::create(['persona_id' => $this->personaAseguradora->id]);
    $this->convenio = GestionConvenios::alta($this->facturacion, $this->aseguradora->id, 'Convenio de prueba', Carbon::parse('2026-10-01'), null, ['Plan Oro']);
    GestionCoberturas::cargarCobertura($this->recepcion, $this->paciente, $this->convenio->planes()->sole()->id, 'AS-0001', 'TITULAR', Carbon::parse('2026-10-01'), null);
    $this->transferencia = MedioPago::where('codigo', 'TRANSFERENCIA')->sole();
    $this->motivo = MotivoAnulacionFactura::where('codigo', 'DATOS_CLIENTE')->sole();
    $this->turnos = 0;

    // La clínica de prueba (RUC inventado con su dígito) y un timbrado vigente 001-001, cargado por Gerencia.
    $clinica = Clinica::create(['tipo_contribuyente' => Clinica::SOCIEDAD, 'razon_social' => 'Clínica de Prueba S.A.', 'ruc' => Ruc::conDigito('80999001'),
        'actividad_economica' => 'Servicios de salud (prueba)', 'direccion_fiscal' => 'Calle Inventada 123']);
    Sucursal::query()->update(['clinica_id' => $clinica->id]);
    $this->timbrado = GestionTimbrados::cargar($this->gerente, ['sucursal_id' => Sucursal::principal()->id, 'numero' => '12345678', 'vigente_desde' => '2026-10-01',
        'vigente_hasta' => '2027-09-30', 'establecimiento' => '001', 'punto_expedicion' => '001', 'numero_inicial' => 1, 'numero_final' => 9999999]);
});

afterEach(fn () => Carbon::setTestNow());

function facturaLiquidacionUsuario(string $codigo): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
}

/** Una prestación de la paciente con la visación de la Consulta (con ese copago) y la firma recibida. Sin copago: ₲ 100.000 de la aseguradora. */
function facturaLiquidacionPrestacion(string $copago = LineaVisacion::NINGUNO, ?string $valor = null): Prestacion
{
    $n = ++test()->turnos;
    $turno = hcTurno(['hora_inicio' => sprintf('%02d:00', 7 + $n), 'hora_fin' => sprintf('%02d:30', 7 + $n), 'procedimiento_id' => test()->consultaProc->id]);
    GestionCoberturas::cargarVisacion(test()->recepcion, test()->paciente, 'turno', $turno->id, "V-{$n}", Carbon::parse('2026-10-06'),
        [['procedimiento_id' => test()->consultaProc->id, 'resultado' => LineaVisacion::APROBADO, 'copago' => $copago, 'valor' => $valor]]);
    test()->actingAs(test()->medico);
    hcGuardarNueva([], $turno)->assertSessionHasNoErrors();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->whereNull('informe_estudio_id')->sole();
    FirmaRecibida::marcar(test()->recepcion, $prestacion);

    return $prestacion->fresh();
}

/** Armada por Facturación por la pantalla (con lo que mostró la vista previa); si se pide, enviada y aprobada por Gerencia. */
function facturaLiquidacionLiquidacion(bool $aprobar = true): Liquidacion
{
    $vista = test()->actingAs(test()->facturacion)->get(route('admin.liquidaciones.create', ['aseguradora' => test()->aseguradora->id, 'corte' => '06/10/2026']))->assertOk();
    test()->post(route('admin.liquidaciones.store'), ['responsable_pago_id' => test()->aseguradora->id, 'fecha_corte' => '06/10/2026',
        'cantidad' => $vista->viewData('prestaciones')->count(), 'total' => $vista->viewData('total')])->assertSessionHasNoErrors();
    $liquidacion = Liquidacion::latest('id')->firstOrFail();
    if ($aprobar) {
        test()->post(route('admin.liquidaciones.enviar', $liquidacion))->assertSessionHas('status');
        test()->actingAs(test()->gerente)->post(route('admin.liquidaciones.aprobar', $liquidacion))->assertSessionHas('status');
    }

    return $liquidacion->fresh();
}

/** Facturación emite la factura de la liquidación por la pantalla. */
function facturaLiquidacionEmitir(Liquidacion $liquidacion, ?User $usuario = null)
{
    return test()->actingAs($usuario ?? test()->facturacion)->from(route('admin.liquidaciones.show', $liquidacion))
        ->post(route('admin.liquidaciones.facturar', $liquidacion));
}

function facturaLiquidacionPagar(Liquidacion $liquidacion, string $monto)
{
    return test()->actingAs(test()->facturacion)->from(route('admin.liquidaciones.show', $liquidacion))->post(route('admin.liquidaciones.pagos.store', $liquidacion), [
        'monto' => $monto, 'fecha' => '06/10/2026', 'medio_pago_id' => test()->transferencia->id, 'referencia' => 'TR-0001',
    ]);
}

function facturaLiquidacionSolicitar(Factura $factura, ?User $usuario = null)
{
    return test()->actingAs($usuario ?? test()->facturacion)->from(route('admin.facturas.show', $factura))
        ->post(route('admin.facturas.solicitar-anulacion-credito', $factura), ['motivo_anulacion_id' => test()->motivo->id]);
}

/** Caja cobra la parte de la paciente (el copago) por la pantalla: factura CONTADO y cobro. */
function facturaLiquidacionCobrarCopago(Prestacion $prestacion)
{
    if (! Caja::where('usuario_id', test()->cajero->id)->exists()) {
        test()->actingAs(test()->cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHas('status');
    }

    return test()->actingAs(test()->cajero)->from(route('admin.cobros.create'))->post(route('admin.cobros.store'), [
        'paciente_id' => test()->paciente->id, 'prestaciones' => [$prestacion->id],
        'lineas' => [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => (string) $prestacion->fresh()->montoPaciente()]],
    ]);
}

test('emisión válida: CRÉDITO a nombre de la aseguradora con su RUC, una línea por prestación con lo de la aseguradora y su IVA; la liquidación queda facturada', function () {
    $completa = facturaLiquidacionPrestacion();                                      // aseguradora 100.000
    $conCopago = facturaLiquidacionPrestacion(LineaVisacion::PORCENTAJE, '20');      // aseguradora 80.000, paciente 20.000
    $liquidacion = facturaLiquidacionLiquidacion();
    expect($liquidacion->facturacion)->toBe(Liquidacion::PENDIENTE_DE_FACTURAR)->and($liquidacion->totalEntero())->toBe(180000);

    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status', fn ($texto) => str_contains($texto, 'Factura 001-001-0000001 emitida'));

    $factura = Factura::sole();
    expect($factura)->condicion->toBe(Factura::CREDITO)->liquidacion_id->toBe($liquidacion->id)->paciente_id->toBeNull()->caja_id->toBeNull()
        ->cliente_nombre->toBe('Seguros de Prueba S.A.')->cliente_documento->toBe('RUC '.Ruc::conDigito('80888001'))
        ->numero_completo->toBe('001-001-0000001')->usuario_id->toBe($this->facturacion->id)->simulada->toBeTrue()
        ->and($factura->tieneEstado(Estado::EMITIDO))->toBeTrue()
        ->and($factura->entero('total'))->toBe(180000)->and($factura->entero('iva_10'))->toBe(9091 + 7273)->and($factura->entero('gravado_10'))->toBe(180000 - 16364)
        ->and($liquidacion->fresh()->facturacion)->toBe(Liquidacion::FACTURADA)
        ->and($liquidacion->fresh()->facturaVigente->id)->toBe($factura->id);
    $lineas = $factura->detalles()->orderBy('id')->get();
    expect($lineas->pluck('prestacion_id')->all())->toBe([$completa->id, $conCopago->id])
        ->and($lineas->pluck('parte')->unique()->all())->toBe([DetalleFactura::ASEGURADORA])
        ->and($lineas->map(fn (DetalleFactura $linea) => [(int) $linea->cantidad, $linea->entero('monto'), $linea->tasa_iva])->all())->toBe([[1, 100000, 10], [1, 80000, 10]])
        ->and($lineas->first()->descripcion)->toBe('Consulta (06/10/2026, visación N.º V-1)');
    expect($this->timbrado->fresh()->numero_actual)->toBe(1);
});

test('el copago de la paciente se factura aparte en Caja: la misma prestación en dos facturas de partes distintas, nunca dos veces la misma parte', function () {
    $prestacion = facturaLiquidacionPrestacion(LineaVisacion::PORCENTAJE, '20');
    facturaLiquidacionCobrarCopago($prestacion)->assertSessionHas('status');     // 0000001, CONTADO, parte PACIENTE (₲ 20.000)
    $liquidacion = facturaLiquidacionLiquidacion();
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status');           // 0000002, CREDITO, parte ASEGURADORA (₲ 80.000)

    expect(Factura::orderBy('id')->get()->map(fn (Factura $factura) => [$factura->numero_completo, $factura->condicion, $factura->entero('total')])->all())
        ->toBe([['001-001-0000001', Factura::CONTADO, 20000], ['001-001-0000002', Factura::CREDITO, 80000]]);
    $prestacion->refresh();
    expect($prestacion->detalleFactura->parte)->toBe(DetalleFactura::PACIENTE)
        ->and($prestacion->detalleFacturaAseguradora->parte)->toBe(DetalleFactura::ASEGURADORA);

    // La misma parte no entra dos veces: el índice único parcial lo frena aunque se saltee la aplicación.
    expect(fn () => DetalleFactura::create(['factura_id' => Factura::latest('id')->value('id'), 'prestacion_id' => $prestacion->id, 'parte' => DetalleFactura::ASEGURADORA,
        'descripcion' => 'Repetida', 'cantidad' => 1, 'precio_unitario' => 80000, 'monto' => 80000, 'tasa_iva' => 10, 'gravado' => 72727, 'iva' => 7273]))
        ->toThrow(QueryException::class);
});

test('aseguradora sin RUC: no se emite, con el aviso de qué falta, y no se usa ningún número', function () {
    $this->personaAseguradora->forceFill(['ruc' => null])->save();
    facturaLiquidacionPrestacion();
    $liquidacion = facturaLiquidacionLiquidacion();

    $this->actingAs($this->facturacion)->get(route('admin.liquidaciones.show', $liquidacion))->assertOk()
        ->assertSee('no tiene RUC')->assertDontSee(route('admin.liquidaciones.facturar', $liquidacion));
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('error', FacturarLiquidacion::sinRuc('Seguros de Prueba S.A.'));

    expect(Factura::count())->toBe(0)->and($this->timbrado->fresh()->numero_actual)->toBe(0)
        ->and($liquidacion->fresh()->facturacion)->toBe(Liquidacion::PENDIENTE_DE_FACTURAR);
});

test('liquidación fuera de estado: en preparación, pendiente de aprobación o anulada no se factura', function () {
    facturaLiquidacionPrestacion();
    $liquidacion = facturaLiquidacionLiquidacion(aprobar: false);
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('error', FacturarLiquidacion::NO_APROBADA);

    $this->post(route('admin.liquidaciones.enviar', $liquidacion))->assertSessionHas('status');
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('error', FacturarLiquidacion::NO_APROBADA);

    FlujoLiquidacion::anular($this->facturacion, $liquidacion->fresh(), 'Se armó por error');
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('error', FacturarLiquidacion::NO_APROBADA);
    expect(Factura::count())->toBe(0)->and($this->timbrado->fresh()->numero_actual)->toBe(0);
});

test('doble emisión de la misma liquidación: la segunda se rechaza; y el índice único parcial frena una segunda no anulada', function () {
    facturaLiquidacionPrestacion();
    $liquidacion = facturaLiquidacionLiquidacion();
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status');

    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('error', FacturarLiquidacion::YA_FACTURADA);
    expect(fn () => FacturarLiquidacion::emitir($this->admin, $liquidacion))->toThrow(AccionRechazada::class, FacturarLiquidacion::YA_FACTURADA);
    expect(Factura::count())->toBe(1)->and($this->timbrado->fresh()->numero_actual)->toBe(1);

    $copia = collect(Factura::sole()->getAttributes())->except(['id', 'numero', 'numero_completo'])->all();
    expect(fn () => DB::table('facturas')->insert([...$copia, 'numero' => 2, 'numero_completo' => '001-001-0000002']))->toThrow(QueryException::class);
});

test('numeración sin saltos: la contado y la de crédito comparten el timbrado; un número anulado no se reutiliza', function () {
    $conCopago = facturaLiquidacionPrestacion(LineaVisacion::PORCENTAJE, '20');
    facturaLiquidacionCobrarCopago($conCopago)->assertSessionHas('status');       // 1
    $liquidacion = facturaLiquidacionLiquidacion();
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status');           // 2
    $credito = Factura::where('condicion', Factura::CREDITO)->sole();
    facturaLiquidacionSolicitar($credito)->assertSessionHas('status');
    $this->actingAs($this->gerente)->post(route('admin.facturas.solicitudes.aprobar', SolicitudAnulacionFactura::sole()))->assertSessionHas('status');
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status');           // 3 (el 2 quedó anulado)
    $otra = facturaLiquidacionPrestacion(LineaVisacion::PORCENTAJE, '20');
    facturaLiquidacionCobrarCopago($otra)->assertSessionHas('status');            // 4

    expect(Factura::orderBy('id')->pluck('numero')->all())->toBe([1, 2, 3, 4])
        ->and(Factura::orderBy('id')->get()->map(fn (Factura $factura) => $factura->condicion)->all())->toBe([Factura::CONTADO, Factura::CREDITO, Factura::CREDITO, Factura::CONTADO])
        ->and($this->timbrado->fresh()->numero_actual)->toBe(4)
        ->and(Factura::where('numero', 2)->sole()->anulada())->toBeTrue();
});

test('el estado de cobro sigue a la liquidación: pagada en parte sigue EMITIDA, pagada queda COBRADA y, si se anula un pago, vuelve a EMITIDA', function () {
    facturaLiquidacionPrestacion();
    facturaLiquidacionPrestacion();
    $liquidacion = facturaLiquidacionLiquidacion();
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status');
    $factura = Factura::sole();

    facturaLiquidacionPagar($liquidacion, '50000')->assertSessionHas('status');
    expect($factura->fresh()->tieneEstado(Estado::EMITIDO))->toBeTrue()->and($factura->fresh()->estadoCobroTexto())->toBe('Pagada en parte (saldo ₲ 150.000)');

    facturaLiquidacionPagar($liquidacion, '150000')->assertSessionHas('status');
    expect($factura->fresh()->tieneEstado(Estado::COBRADO))->toBeTrue()->and($factura->fresh()->estadoCobroTexto())->toBe('Cobrada (la liquidación está pagada)');

    $this->actingAs($this->facturacion)->post(route('admin.liquidaciones.pagos.anular', PagoAseguradora::latest('id')->first()), ['motivo_pago' => 'Cargado por error'])
        ->assertSessionHas('status');
    expect($factura->fresh()->tieneEstado(Estado::EMITIDO))->toBeTrue()->and($liquidacion->fresh()->tieneEstado(Estado::PARCIAL))->toBeTrue();

    // Emitida sobre una liquidación ya pagada, nace cobrada.
    $otra = facturaLiquidacionPrestacion();
    $segunda = facturaLiquidacionLiquidacion();
    facturaLiquidacionPagar($segunda, '100000')->assertSessionHas('status');
    facturaLiquidacionEmitir($segunda)->assertSessionHas('status');
    expect(Factura::where('liquidacion_id', $segunda->id)->sole()->tieneEstado(Estado::COBRADO))->toBeTrue();

    // La inmutabilidad sigue: la contado no vuelve a EMITIDA y la de crédito no cambia nada más que el estado.
    facturaLiquidacionCobrarCopago(facturaLiquidacionPrestacion(LineaVisacion::PORCENTAJE, '20'))->assertSessionHas('status');
    $contado = Factura::where('condicion', Factura::CONTADO)->sole();
    expect(fn () => $contado->forceFill(['estado_id' => Estado::idDe(Estado::EMITIDO)])->save())->toThrow(RegistroEconomicoInmutable::class);
    $credito = Factura::where('liquidacion_id', $segunda->id)->sole();
    expect(fn () => $credito->forceFill(['estado_id' => Estado::idDe(Estado::EMITIDO), 'total' => 1])->save())->toThrow(RegistroEconomicoInmutable::class);
    expect(fn () => $factura->fresh()->forceFill(['cliente_nombre' => 'Otra'])->save())->toThrow(RegistroEconomicoInmutable::class);
    expect(fn () => $factura->fresh()->delete())->toThrow(RegistroEconomicoInmutable::class);
});

test('anulación: con pagos vigentes no se pide ni se aprueba; sin pagos, con la aprobación de Gerencia, sin caja: libera las líneas y la liquidación vuelve a pendiente de facturar', function () {
    $prestacion = facturaLiquidacionPrestacion();
    $liquidacion = facturaLiquidacionLiquidacion();
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status');
    $factura = Factura::sole();
    $repartos = DB::table('repartos_prestacion')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all();
    $lineasReparto = DB::table('lineas_reparto')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all();

    // Sin anulación directa: la de crédito siempre con la solicitud.
    expect(fn () => AnularFactura::anular($this->admin, $factura, $this->motivo->id, null))->toThrow(AccionRechazada::class, AnularFactura::CREDITO_CON_APROBACION);

    // Con un pago vigente, no se pide.
    facturaLiquidacionPagar($liquidacion, '30000')->assertSessionHas('status');
    facturaLiquidacionSolicitar($factura)->assertSessionHas('error', AnularFactura::CREDITO_CON_PAGOS);
    $this->actingAs($this->facturacion)->get(route('admin.facturas.show', $factura))->assertOk()->assertSee(AnularFactura::CREDITO_CON_PAGOS);
    $pago = PagoAseguradora::sole();
    $this->post(route('admin.liquidaciones.pagos.anular', $pago), ['motivo_pago' => 'Cargado por error'])->assertSessionHas('status');

    // Sin pagos, se pide; si mientras tanto entra un pago, no se aprueba.
    facturaLiquidacionSolicitar($factura)->assertSessionHas('status');
    $solicitud = SolicitudAnulacionFactura::sole();
    facturaLiquidacionPagar($liquidacion, '30000')->assertSessionHas('status');
    $this->actingAs($this->gerente)->post(route('admin.facturas.solicitudes.aprobar', $solicitud))->assertSessionHas('error', AnularFactura::CREDITO_CON_PAGOS);
    expect($factura->fresh()->anulada())->toBeFalse();
    $this->actingAs($this->facturacion)->post(route('admin.liquidaciones.pagos.anular', PagoAseguradora::whereNull('anulado_en')->sole()), ['motivo_pago' => 'Cargado por error']);

    $movimientos = MovimientoCaja::count();
    $this->actingAs($this->gerente)->post(route('admin.facturas.solicitudes.aprobar', $solicitud))
        ->assertSessionHas('status', "Anulación aprobada: la factura 001-001-0000001 quedó anulada y la liquidación N.º {$liquidacion->id} vuelve a estar pendiente de facturar.");

    $factura->refresh();
    expect($factura->anulada())->toBeTrue()->and($factura->anulada_por)->toBe($this->gerente->id)
        ->and($factura->detalles()->whereNull('liberada_en')->count())->toBe(0)
        ->and($liquidacion->fresh()->facturacion)->toBe(Liquidacion::PENDIENTE_DE_FACTURAR)
        ->and($prestacion->fresh()->detalleFacturaAseguradora)->toBeNull()
        ->and(MovimientoCaja::count())->toBe($movimientos)
        ->and(DB::table('repartos_prestacion')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all())->toBe($repartos)
        ->and(DB::table('lineas_reparto')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all())->toBe($lineasReparto);
    // La prestación sigue bloqueada por la liquidación aprobada.
    expect($prestacion->fresh()->lineaLiquidacion)->not->toBeNull();
});

test('nadie aprueba la propia solicitud de anulación; y la liquidación no se anula con su factura vigente', function () {
    facturaLiquidacionPrestacion();
    $liquidacion = facturaLiquidacionLiquidacion();
    facturaLiquidacionEmitir($liquidacion, $this->admin)->assertSessionHas('status');
    $factura = Factura::sole();

    $this->actingAs($this->gerente)->from(route('admin.liquidaciones.show', $liquidacion))
        ->post(route('admin.liquidaciones.anular', $liquidacion), ['motivo_anulacion' => 'Se armó por error'])
        ->assertSessionHas('error', FlujoLiquidacion::conFactura('001-001-0000001'));
    $this->get(route('admin.liquidaciones.show', $liquidacion))->assertOk()->assertDontSee(route('admin.liquidaciones.anular', $liquidacion));

    facturaLiquidacionSolicitar($factura, $this->admin)->assertSessionHas('status');
    $this->actingAs($this->admin)->post(route('admin.facturas.solicitudes.aprobar', SolicitudAnulacionFactura::sole()))->assertSessionHas('error', AnularFactura::PROPIA);
    expect($factura->fresh()->anulada())->toBeFalse();

    $this->actingAs($this->gerente)->post(route('admin.facturas.solicitudes.aprobar', SolicitudAnulacionFactura::sole()))->assertSessionHas('status');
    // Anulada la factura, ahora sí se anula la liquidación.
    $this->post(route('admin.liquidaciones.anular', $liquidacion), ['motivo_anulacion' => 'Se armó por error'])->assertSessionHas('status');
    expect($liquidacion->fresh()->anulada())->toBeTrue();
});

test('trazabilidad: la factura enlaza la liquidación y sus prestaciones; la liquidación, su factura; la prestación, sus dos facturas; el PDF dice CRÉDITO y la liquidación', function () {
    $prestacion = facturaLiquidacionPrestacion(LineaVisacion::PORCENTAJE, '20');
    facturaLiquidacionCobrarCopago($prestacion)->assertSessionHas('status');
    $liquidacion = facturaLiquidacionLiquidacion();
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status');
    [$contado, $credito] = [Factura::where('condicion', Factura::CONTADO)->sole(), Factura::where('condicion', Factura::CREDITO)->sole()];

    $this->actingAs($this->facturacion)->get(route('admin.facturas.show', $credito))->assertOk()
        ->assertSee('Crédito')->assertSee("Liquidación N.º {$liquidacion->id}")->assertSee(route('admin.liquidaciones.show', $liquidacion))
        ->assertSee(route('admin.prestaciones.show', $prestacion->id))->assertSee('Solicitar la anulación')->assertDontSee('Anular la factura');
    $this->get(route('admin.liquidaciones.show', $liquidacion))->assertOk()
        ->assertSee('Factura 001-001-0000002')->assertSee(route('admin.facturas.show', $credito))->assertSee('Facturada');
    $this->get(route('admin.prestaciones.show', $prestacion))->assertOk()
        ->assertSee(route('admin.facturas.show', $contado))->assertSee(route('admin.facturas.show', $credito))->assertSee('Factura de la aseguradora');
    // Recepción ve en la prestación solo el número de la factura de la paciente; la de la aseguradora, no.
    $this->actingAs($this->recepcion)->get(route('admin.prestaciones.show', $prestacion))->assertOk()
        ->assertSee('001-001-0000001')->assertDontSee('001-001-0000002')->assertDontSee('Factura de la aseguradora');

    $html = PdfFactura::html($credito);
    expect($html)->toContain('CRÉDITO')->toContain("Liquidación N.º {$liquidacion->id} (prestaciones del 06/10/2026 al 06/10/2026)")
        ->toContain('Seguros de Prueba S.A.')->toContain('RUC '.Ruc::conDigito('80888001'))->toContain('Consulta (06/10/2026, visación N.º V-1)')
        ->toContain('₲ 80.000')->not->toContain('Carmen Duarte')->not->toContain('Rinofaringitis');
    expect(PdfFactura::html($contado))->toContain('CONTADO')->not->toContain('Liquidación N.º');
});

test('permisos: emite Facturación y convenios (y el Administrador); Caja, Gerencia, Recepción y Auditor no; Gerencia ve la factura y la liquidación y aprueba', function () {
    facturaLiquidacionPrestacion();
    $liquidacion = facturaLiquidacionLiquidacion();

    foreach ([PerfilesPredefinidos::CAJA, PerfilesPredefinidos::GERENCIA, PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::AUDITOR, PerfilesPredefinidos::MEDICO] as $perfil) {
        facturaLiquidacionEmitir($liquidacion, facturaLiquidacionUsuario($perfil))->assertForbidden();
    }
    $this->actingAs($this->gerente)->get(route('admin.liquidaciones.show', $liquidacion))->assertOk()->assertDontSee(route('admin.liquidaciones.facturar', $liquidacion));
    $this->actingAs($this->facturacion)->get(route('admin.liquidaciones.show', $liquidacion))->assertOk()->assertSee(route('admin.liquidaciones.facturar', $liquidacion));
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status');
    $factura = Factura::sole();

    // Caja no solicita la anulación de la de crédito: ni por su ruta (no tiene el permiso) ni por la de la contado.
    facturaLiquidacionSolicitar($factura, $this->cajero)->assertForbidden();
    $this->actingAs($this->cajero)->from(route('admin.facturas.show', $factura))
        ->post(route('admin.facturas.solicitar-anulacion', $factura), ['motivo_anulacion_id' => $this->motivo->id])->assertSessionHas('error');
    $this->actingAs($this->cajero)->get(route('admin.facturas.show', $factura))->assertOk()->assertDontSee('Solicitar la anulación');
    expect(SolicitudAnulacionFactura::count())->toBe(0);

    // Gerencia ve la factura, su PDF y la liquidación, y aprueba la solicitud desde la factura.
    facturaLiquidacionSolicitar($factura)->assertSessionHas('status');
    $this->actingAs($this->gerente)->get(route('admin.facturas.show', $factura))->assertOk()->assertSee('Aprobar la anulación')->assertSee(route('admin.liquidaciones.show', $liquidacion));
    $this->get(route('admin.facturas.pdf', $factura))->assertOk();
    $this->get(route('admin.facturas.solicitudes'))->assertOk()->assertSee('001-001-0000001');
    $this->get(route('admin.liquidaciones.show', $liquidacion))->assertOk();

    // La matriz: solo Facturación y convenios (y el Administrador) tienen FACTURAS_LIQUIDACION; los demás permisos no cambian.
    foreach (PerfilesPredefinidos::MATRIZ as $codigo => $modulos) {
        expect($modulos['FACTURAS_LIQUIDACION'] ?? [])->toBe($codigo === PerfilesPredefinidos::FACTURACION ? ['VER', 'CREAR', 'DESACTIVAR'] : [], $codigo);
    }
    expect($this->admin->tienePermiso('FACTURAS_LIQUIDACION', 'CREAR'))->toBeTrue()
        ->and($this->cajero->tienePermiso('FACTURAS_LIQUIDACION', 'CREAR'))->toBeFalse()
        ->and($this->gerente->tienePermiso('ANULACIONES_FACTURA', 'EDITAR'))->toBeTrue();
});

test('auditoría: la emisión, el cobro y su vuelta, la solicitud, la aprobación y la liquidación de vuelta a pendiente; con los montos ocultos a quien no ve facturas', function () {
    facturaLiquidacionPrestacion();
    $liquidacion = facturaLiquidacionLiquidacion();
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status');
    $factura = Factura::sole();
    facturaLiquidacionPagar($liquidacion, '100000')->assertSessionHas('status');
    $this->actingAs($this->facturacion)->post(route('admin.liquidaciones.pagos.anular', PagoAseguradora::sole()), ['motivo_pago' => 'Cargado por error']);
    facturaLiquidacionSolicitar($factura)->assertSessionHas('status');
    $this->actingAs($this->gerente)->post(route('admin.facturas.solicitudes.aprobar', SolicitudAnulacionFactura::sole()))->assertSessionHas('status');

    $eventos = fn (string $tabla, AccionAuditoria $accion) => LogAuditoria::where('tabla_afectada', $tabla)->where('accion', $accion->value)->orderBy('id')->pluck('detalle')->all();
    expect($eventos('facturas', AccionAuditoria::CREAR))->toBe(['Factura 001-001-0000001'])
        ->and($eventos('detalles_factura', AccionAuditoria::CREAR))->toBe(['Factura 001-001-0000001'])
        ->and($eventos('facturas', AccionAuditoria::EDITAR))->toBe(['Factura 001-001-0000001 cobrada', 'Factura 001-001-0000001 vuelve a pendiente de cobro'])
        ->and($eventos('facturas', AccionAuditoria::ANULAR))->toBe(['Factura 001-001-0000001 anulada. Motivo: '.$this->motivo->nombre])
        ->and($eventos('solicitudes_anulacion_factura', AccionAuditoria::CREAR))->toBe(['Solicitud de anulación de la factura 001-001-0000001'])
        ->and(collect($eventos('liquidaciones', AccionAuditoria::EDITAR)))->toContain('Liquidación facturada: factura 001-001-0000001')
        ->toContain('Vuelve a pendiente de facturar: factura 001-001-0000001 anulada');
    expect(LogAuditoria::where('tabla_afectada', 'facturas')->where('accion', AccionAuditoria::CREAR->value)->sole()->usuario_id)->toBe($this->facturacion->id);

    // El Auditor ve los eventos, sin los montos (no ve las facturas).
    $auditor = facturaLiquidacionUsuario(PerfilesPredefinidos::AUDITOR);
    $creacion = LogAuditoria::where('tabla_afectada', 'facturas')->where('accion', AccionAuditoria::CREAR->value)->sole();
    $this->actingAs($auditor)->get(route('admin.auditoria.show', $creacion))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Facturas')->assertDontSee('90909');
    $this->actingAs($this->gerente)->get(route('admin.auditoria.show', $creacion))->assertOk()->assertDontSee('Contenido económico')->assertSee('90909');
});

test('la marca de agua "SIMULACIÓN, SIN VALIDEZ FISCAL" está en el PDF de una factura vieja (contado, anterior a esta fase) y de una nueva (crédito); el PDF sale', function () {
    $prestacion = facturaLiquidacionPrestacion(LineaVisacion::PORCENTAJE, '20');
    facturaLiquidacionCobrarCopago($prestacion)->assertSessionHas('status');
    // La vieja: una contado como las de antes de esta fase (sin liquidación), con su fecha anterior.
    $vieja = Factura::sole();
    DB::table('facturas')->where('id', $vieja->id)->update(['emitida_en' => '2026-10-02 12:00:00']);
    $liquidacion = facturaLiquidacionLiquidacion();
    facturaLiquidacionEmitir($liquidacion)->assertSessionHas('status');
    $nueva = Factura::where('condicion', Factura::CREDITO)->sole();

    foreach ([$vieja->fresh(), $nueva] as $factura) {
        $html = PdfFactura::html($factura);
        expect($html)->toContain('<div class="marca-agua">SIMULACIÓN, SIN VALIDEZ FISCAL</div>')
            ->toContain('position: fixed')->toContain('rotate(-38deg)')->not->toContain('class="simulacion"');
        $respuesta = $this->actingAs($this->gerente)->get(route('admin.facturas.pdf', $factura))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($respuesta->getContent(), 0, 5))->toBe('%PDF-');
    }
    // Las pantallas ya no llevan el cartel.
    $this->actingAs($this->facturacion)->get(route('admin.facturas.show', $nueva))->assertOk()->assertDontSee('SIN VALIDEZ FISCAL');
});
