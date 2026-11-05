<?php

/*
 * Facturación individual simulada (docs/facturacion.md): cobrar emite la factura y el cobro juntos (con una falla a mitad no queda
 * nada), la numeración correlativa sin saltos ni reutilizada, sin timbrado vigente (o vencido, agotado, sin datos de la clínica) no se
 * emite, el IVA 10, 5 y exenta con la suma exacta, una prestación se factura una sola vez, lo de la aseguradora fuera de la factura,
 * la prestación facturada bloqueada, la anulación directa en el plazo y la solicitud en los demás casos, nadie aprueba la propia, los
 * cobros anteriores intactos, el PDF con su marca, los permisos y la auditoría con los montos ocultos. Datos, precios y números
 * inventados. Prefijo "facturacion".
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Caja;
use App\Models\Clinica;
use App\Models\Cobro;
use App\Models\Consulta;
use App\Models\DetalleFactura;
use App\Models\Estado;
use App\Models\Factura;
use App\Models\LogAuditoria;
use App\Models\MedioPago;
use App\Models\MotivoAnulacionFactura;
use App\Models\MovimientoCaja;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\SolicitudAnulacionFactura;
use App\Models\Sucursal;
use App\Models\Timbrado;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\RevertirCobro;
use App\Support\Convenios\AplicarVisacion;
use App\Support\Facturacion\AnularFactura;
use App\Support\Facturacion\EmisorDocumentos;
use App\Support\Facturacion\EmitirFactura;
use App\Support\Facturacion\GestionTimbrados;
use App\Support\Facturacion\Iva;
use App\Support\Facturacion\PdfFactura;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\AnularPrestacion;
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
    $this->eco = Procedimiento::create(['codigo' => 'ECO-PRUEBA', 'nombre' => 'Ecografía de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);
    $this->cajero = facturacionUsuario(PerfilesPredefinidos::CAJA, ['apellidos' => 'Cáceres', 'nombres' => 'Lidia']);
    $this->otroCajero = facturacionUsuario(PerfilesPredefinidos::CAJA, ['apellidos' => 'Ortiz', 'nombres' => 'Marta']);
    $this->gerente = facturacionUsuario(PerfilesPredefinidos::GERENCIA, ['apellidos' => 'Gómez', 'nombres' => 'Ana']);
    // Las migraciones guardaron la hora real en que corrieron; el "hoy" de estos tests es anterior. Desde el 01/10 se factura.
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO, EmitirFactura::HITO])
        ->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $this->consultaProc->id, null, 100000, Carbon::parse('2026-10-01'));
    AltaTarifa::ejecutar($this->gerente, $this->eco->id, null, 250000, Carbon::parse('2026-10-01'));
    $this->efectivo = MedioPago::where('codigo', MedioPago::EFECTIVO)->sole();
    $this->tarjeta = MedioPago::where('codigo', MedioPago::TARJETA)->sole();
    [$this->datosCliente, $this->otroMotivo] = [MotivoAnulacionFactura::where('codigo', 'DATOS_CLIENTE')->sole(), MotivoAnulacionFactura::where('codigo', 'OTRO')->sole()];
});

afterEach(fn () => Carbon::setTestNow());

function facturacionUsuario(string $codigo, array $persona = []): User
{
    $factory = User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()]);

    return ($persona ? $factory->conPersona($persona) : $factory)->create();
}

/** La clínica de prueba (RUC inventado con su dígito) y su sucursal. */
function facturacionClinica(): Clinica
{
    $clinica = Clinica::create(['tipo_contribuyente' => Clinica::SOCIEDAD, 'razon_social' => 'Clínica de Prueba S.A.', 'nombre_fantasia' => 'Plenitud de Prueba',
        'ruc' => Ruc::conDigito('80999001'), 'actividad_economica' => 'Servicios de salud (prueba)', 'direccion_fiscal' => 'Calle Inventada 123']);
    Sucursal::query()->update(['clinica_id' => $clinica->id]);

    return $clinica;
}

/** La clínica y un timbrado vigente (001-001, del 1 al $final), cargado por Gerencia. */
function facturacionTimbrado(int $final = 9999999, string $desde = '2026-10-01', string $hasta = '2027-09-30', int $inicial = 1): Timbrado
{
    Clinica::actual() ?? facturacionClinica();

    return GestionTimbrados::cargar(test()->gerente, ['sucursal_id' => Sucursal::principal()->id, 'numero' => '12345678', 'vigente_desde' => $desde,
        'vigente_hasta' => $hasta, 'establecimiento' => '001', 'punto_expedicion' => '001', 'numero_inicial' => $inicial, 'numero_final' => $final]);
}

/** La prestación de una consulta finalizada de la paciente (de consulta, ₲ 100.000; o de ecografía, ₲ 250.000). */
function facturacionPrestacion(bool $ecografia = false): Prestacion
{
    test()->actingAs(test()->medico);
    if ($ecografia) {
        hcGuardarNueva([], hcTurno(['procedimiento_id' => test()->eco->id]))->assertSessionHasNoErrors();
    } else {
        hcConsulta();
    }

    return Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
}

function facturacionAbrir(User $cajero, string $monto = '500.000'): Caja
{
    test()->actingAs($cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => $monto])->assertSessionHas('status');

    return Caja::where('usuario_id', $cajero->id)->latest('id')->firstOrFail();
}

/** Cobra (factura y cobro) por la pantalla, en efectivo por el total. */
function facturacionCobrar(User $cajero, array $prestaciones, array $extra = [])
{
    $total = collect($prestaciones)->sum(fn (Prestacion $prestacion) => $prestacion->fresh()->montoPaciente());

    return test()->actingAs($cajero)->from(route('admin.cobros.create'))->post(route('admin.cobros.store'), [
        'paciente_id' => test()->paciente->id,
        'prestaciones' => array_map(fn (Prestacion $prestacion) => $prestacion->id, $prestaciones),
        'lineas' => [['medio_pago_id' => test()->efectivo->id, 'monto' => (string) $total]],
        ...$extra,
    ]);
}

function facturacionAnular(User $usuario, Factura $factura, ?MotivoAnulacionFactura $motivo = null, ?string $detalle = null)
{
    return test()->actingAs($usuario)->from(route('admin.facturas.show', $factura))->post(route('admin.facturas.anular', $factura),
        ['motivo_anulacion_id' => ($motivo ?? test()->datosCliente)->id, 'detalle' => $detalle]);
}

function facturacionSolicitar(User $usuario, Factura $factura, ?string $detalle = null)
{
    return test()->actingAs($usuario)->from(route('admin.facturas.show', $factura))->post(route('admin.facturas.solicitar-anulacion', $factura),
        ['motivo_anulacion_id' => test()->datosCliente->id, 'detalle' => $detalle]);
}

/** Cierra la caja con lo esperado (sin diferencia). */
function facturacionCerrar(User $cajero, Caja $caja): void
{
    $contado = MedioPago::pluck('id')->mapWithKeys(fn ($id) => [$id => (string) ($caja->esperadoPorMedio()[$id] ?? 0)])->all();
    test()->actingAs($cajero)->post(route('admin.cajas.cerrar', $caja), ['contado' => $contado])->assertSessionHas('status');
}

test('cobrar emite la factura contado y el cobro en la misma operación, con el snapshot del emisor y del cliente', function () {
    $timbrado = facturacionTimbrado();
    $prestacion = facturacionPrestacion();
    $caja = facturacionAbrir($this->cajero);

    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHasNoErrors()->assertSessionHas('status', 'Factura 001-001-0000001 emitida y cobrada: ₲ 100.000.');

    $factura = Factura::sole();
    $cobro = Cobro::sole();
    $persona = $this->paciente->persona;
    expect($factura)
        ->numero_completo->toBe('001-001-0000001')->numero->toBe(1)->numero_timbrado->toBe('12345678')->condicion->toBe(Factura::CONTADO)
        ->emisor_razon_social->toBe('Clínica de Prueba S.A.')->emisor_ruc->toBe(Ruc::conDigito('80999001'))->emisor_direccion->toBe('Calle Inventada 123')
        ->cliente_nombre->toBe('Carmen Duarte')->cliente_documento->toBe($persona->documentoPrincipal())
        ->usuario_id->toBe($this->cajero->id)->caja_id->toBe($caja->id)->simulada->toBeTrue()->emisor_documentos->toBe('SIMULADO')
        ->and($factura->tieneEstado(Estado::COBRADO))->toBeTrue()
        ->and($factura->entero('total'))->toBe(100000)->and($factura->entero('iva_10'))->toBe(9091)->and($factura->entero('gravado_10'))->toBe(90909)
        ->and($cobro->factura_id)->toBe($factura->id)->and($cobro->caja_id)->toBe($caja->id)
        ->and($timbrado->fresh()->numero_actual)->toBe(1)
        ->and($factura->detalles()->sole())->prestacion_id->toBe($prestacion->id)->descripcion->toBe('Consulta')->cantidad->toBe(1)->tasa_iva->toBe(10)
        ->and($prestacion->fresh()->estadoCobroTexto())->toBe(Prestacion::COBRADA);
    // La factura antes que el cobro (y la línea de la factura antes que la del cobro), en la Auditoría.
    $creados = LogAuditoria::where('accion', AccionAuditoria::CREAR->value)->whereIn('tabla_afectada', ['facturas', 'detalles_factura', 'cobros'])->orderBy('id')->pluck('tabla_afectada')->all();
    expect($creados)->toBe(['facturas', 'detalles_factura', 'cobros']);

    // Un cambio posterior de los datos de la clínica no altera la emitida.
    Clinica::actual()->update(['razon_social' => 'Otra razón social S.A.']);
    expect($factura->fresh()->emisor_razon_social)->toBe('Clínica de Prueba S.A.');
});

test('con una falla a mitad (el emisor rechaza) no queda nada: ni la factura, ni el número, ni el cobro, ni el movimiento', function () {
    $timbrado = facturacionTimbrado();
    $prestacion = facturacionPrestacion();
    $caja = facturacionAbrir($this->cajero);
    app()->bind(EmisorDocumentos::class, fn () => new class implements EmisorDocumentos
    {
        public function codigo(): string
        {
            return 'FALLA';
        }

        public function simulado(): bool
        {
            return true;
        }

        public function emitir(Factura $factura): void
        {
            throw new AccionRechazada('El emisor de prueba no responde.');
        }

        public function anular(Factura $factura): void {}
    });

    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHas('error', 'El emisor de prueba no responde.');

    expect(Factura::count())->toBe(0)->and(DetalleFactura::count())->toBe(0)->and(Cobro::count())->toBe(0)
        ->and(MovimientoCaja::where('caja_id', $caja->id)->where('tipo', MovimientoCaja::COBRO)->count())->toBe(0)
        ->and($timbrado->fresh()->numero_actual)->toBe(0)
        ->and(LogAuditoria::whereIn('tabla_afectada', ['facturas', 'cobros'])->count())->toBe(0);
});

test('la numeración es correlativa, sin saltos, y un número anulado no se vuelve a usar', function () {
    facturacionTimbrado();
    facturacionAbrir($this->cajero);
    foreach (range(1, 3) as $vez) {
        facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    }
    expect(Factura::orderBy('id')->pluck('numero_completo')->all())->toBe(['001-001-0000001', '001-001-0000002', '001-001-0000003']);

    $segunda = Factura::where('numero', 2)->sole();
    facturacionAnular($this->cajero, $segunda)->assertSessionHas('status');
    // La prestación de la anulada se vuelve a facturar con el número siguiente, no con el 2.
    facturacionCobrar($this->cajero, [$segunda->detalles()->sole()->prestacion], ['reemplaza_a_id' => $segunda->id])->assertSessionHasNoErrors();

    $nueva = Factura::latest('id')->first();
    expect($nueva->numero_completo)->toBe('001-001-0000004')->and($nueva->reemplaza_a_id)->toBe($segunda->id)
        ->and($segunda->fresh()->anulada())->toBeTrue()->and($segunda->fresh()->numero_completo)->toBe('001-001-0000002')
        ->and(Factura::pluck('numero')->sort()->values()->all())->toBe([1, 2, 3, 4]);
    // El número no se repite en el timbrado ni aunque algo se saltee el bloqueo.
    expect(fn () => DB::table('facturas')->insert([...collect(DB::table('facturas')->where('id', $nueva->id)->first())->except('id')->all()]))
        ->toThrow(QueryException::class);
});

test('sin timbrado vigente, vencido, agotado o sin datos de la clínica no se emite (ni se cobra), con un aviso claro', function () {
    $prestacion = facturacionPrestacion();
    facturacionAbrir($this->cajero);

    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHas('error', Clinica::faltaParaEmitir(null));
    facturacionClinica();
    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHas('error', EmitirFactura::SIN_TIMBRADO);
    $this->actingAs($this->cajero)->get(route('admin.cobros.create', ['paciente' => $this->paciente->id]))->assertOk()->assertSee(EmitirFactura::SIN_TIMBRADO);

    $vencido = facturacionTimbrado(hasta: '2026-10-05');
    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHas('error', 'El timbrado N.º 12345678 venció el 05/10/2026: Gerencia o el Administrador cargan el nuevo en Facturas → Timbrados.');

    $agotado = facturacionTimbrado(final: 1);
    expect($vencido->fresh()->historico())->toBeTrue();
    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHasNoErrors();
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHas('error', 'El timbrado N.º 12345678 llegó a su último número (1): Gerencia o el Administrador cargan el nuevo en Facturas → Timbrados.');

    expect(Factura::count())->toBe(1)->and(Cobro::count())->toBe(1)->and($agotado->fresh()->numero_actual)->toBe(1);
});

test('IVA con precios incluidos: 10 % = monto / 11 y 5 % = monto / 21 al guaraní, la exenta sin IVA, y los totales suman exacto', function () {
    expect(Iva::desglose(100000, 10))->toBe(['gravado' => 90909, 'iva' => 9091])
        ->and(Iva::desglose(250000, 10))->toBe(['gravado' => 227273, 'iva' => 22727])
        ->and(Iva::desglose(100000, 5))->toBe(['gravado' => 95238, 'iva' => 4762])
        ->and(Iva::desglose(33333, 5))->toBe(['gravado' => 31746, 'iva' => 1587])
        ->and(Iva::desglose(100000, 0))->toBe(['gravado' => 100000, 'iva' => 0])
        // Lo más cerca de la mitad: 16 / 11 = 1,4545… baja; 17 / 11 = 1,5454… sube.
        ->and(Iva::desglose(16, 10)['iva'])->toBe(1)->and(Iva::desglose(17, 10)['iva'])->toBe(2);
    foreach ([[1, 10], [7, 5], [999999, 10], [123457, 5], [50, 0]] as [$monto, $tasa]) {
        $desglose = Iva::desglose($monto, $tasa);
        expect($desglose['gravado'] + $desglose['iva'])->toBe($monto)
            ->and($desglose['iva'])->toBe($tasa === 0 ? 0 : (int) round($monto / ($tasa === 10 ? 11 : 21), 0, PHP_ROUND_HALF_UP));
    }

    // Una factura con las tres tasas (la tasa del procedimiento se copia a la línea).
    facturacionTimbrado();
    $this->eco->update(['tasa_iva' => 5]);
    $exento = Procedimiento::create(['codigo' => 'EXENTO-1', 'nombre' => 'Estudio exento de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30, 'tasa_iva' => 0]);
    AltaTarifa::ejecutar($this->gerente, $exento->id, null, 77777, Carbon::parse('2026-10-01'));
    $prestaciones = [facturacionPrestacion(), facturacionPrestacion(ecografia: true)];
    test()->actingAs(test()->medico);
    hcGuardarNueva([], hcTurno(['procedimiento_id' => $exento->id, 'hora_inicio' => '10:00', 'hora_fin' => '10:30']))->assertSessionHasNoErrors();
    $prestaciones[] = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, $prestaciones)->assertSessionHasNoErrors();

    $factura = Factura::sole();
    expect($factura->detalles()->orderBy('id')->pluck('tasa_iva')->all())->toBe([10, 5, 0])
        ->and(collect(['gravado_10', 'iva_10', 'gravado_5', 'iva_5', 'exento', 'total'])->mapWithKeys(fn ($c) => [$c => $factura->entero($c)])->all())
        ->toBe(['gravado_10' => 90909, 'iva_10' => 9091, 'gravado_5' => 238095, 'iva_5' => 11905, 'exento' => 77777, 'total' => 427777])
        ->and($factura->entero('gravado_10') + $factura->entero('iva_10') + $factura->entero('gravado_5') + $factura->entero('iva_5') + $factura->entero('exento'))->toBe(427777);
    // Cambiar la tasa después no cambia la emitida.
    $this->eco->update(['tasa_iva' => 10]);
    expect($factura->detalles()->orderBy('id')->pluck('tasa_iva')->all())->toBe([10, 5, 0]);
});

test('una prestación se factura una sola vez (también frente al índice único parcial) y la anulada la libera', function () {
    facturacionTimbrado();
    $prestacion = facturacionPrestacion();
    $caja = facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHasNoErrors();
    $factura = Factura::sole();

    // Aunque algo se saltee la verificación del cobro, la factura no se emite dos veces.
    expect(fn () => DB::transaction(fn () => EmitirFactura::contado($this->cajero, $caja, $this->paciente->id, [$prestacion->fresh()])))
        ->toThrow(AccionRechazada::class, EmitirFactura::YA_FACTURADA);
    expect(fn () => DB::table('detalles_factura')->insert([...collect(DB::table('detalles_factura')->first())->except('id')->all()]))
        ->toThrow(QueryException::class);

    facturacionAnular($this->cajero, $factura)->assertSessionHas('status');
    expect($factura->detalles()->sole()->liberada_en)->not->toBeNull()
        ->and($prestacion->fresh()->detalleFactura)->toBeNull();
    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHasNoErrors();
    expect(DetalleFactura::where('prestacion_id', $prestacion->id)->whereNull('liberada_en')->count())->toBe(1);
});

test('lo de la aseguradora no entra en la factura individual: solo la parte de la paciente (el copago)', function () {
    facturacionTimbrado();
    $prestacion = facturacionPrestacion();
    // Copago inventado: la paciente paga ₲ 30.000; la aseguradora, ₲ 70.000 (se liquida aparte).
    DB::table('prestaciones')->where('id', $prestacion->id)->update(['pagador' => Prestacion::ASEGURADORA, 'monto_paciente' => 30000, 'monto_aseguradora' => 70000]);
    facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHasNoErrors();

    $factura = Factura::sole();
    expect($factura->entero('total'))->toBe(30000)->and($factura->detalles()->sole()->entero('monto'))->toBe(30000)
        ->and($factura->entero('iva_10'))->toBe(2727)->and(Cobro::sole()->total)->toEqual(30000);
});

test('una prestación facturada no cambia de visación ni de monto ni se anula, con un aviso claro', function () {
    facturacionTimbrado();
    $prestacion = facturacionPrestacion();
    facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHasNoErrors();
    $aviso = 'Esta prestación está facturada (factura 001-001-0000001): no se pueden cambiar su visación ni su monto, ni anularla. Primero se anula la factura.';

    DB::table('hitos_sistema')->where('codigo', 'CONVENIOS')->update(['fecha_hora' => '2026-10-01 00:00:00']);
    expect(AplicarVisacion::impedimento($prestacion->fresh()))->toBe($aviso)
        ->and(fn () => AnularPrestacion::ejecutar($this->gerente, $prestacion->fresh(), 'Motivo inventado de anulación'))->toThrow(AccionRechazada::class, $aviso);
    $this->actingAs($this->gerente)->get(route('admin.prestaciones.show', $prestacion))->assertOk()
        ->assertSee('001-001-0000001')->assertSee('Está facturada: para anularla, primero hay que anular su factura');
});

test('el cajero anula una factura suya dentro de los 10 minutos: se revierte su cobro desde su caja y se anula, en una sola acción', function () {
    facturacionTimbrado();
    $prestacion = facturacionPrestacion();
    $caja = facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [$prestacion])->assertSessionHasNoErrors();
    $factura = Factura::sole();
    $this->actingAs($this->cajero)->get(route('admin.facturas.show', $factura))->assertOk()->assertSee('Anular la factura')->assertDontSee('Solicitar la anulación');

    // "Otro (detallar)" sin detalle: se rechaza.
    facturacionAnular($this->cajero, $factura, $this->otroMotivo)->assertSessionHas('error', 'Detalle el motivo de la anulación (al menos 5 caracteres).');
    Carbon::setTestNow(now()->addMinutes(10));
    facturacionAnular($this->cajero, $factura, $this->otroMotivo, 'Se cargó a otra paciente')->assertSessionHas('status');

    $factura->refresh();
    $cobro = Cobro::sole();
    expect($factura->anulada())->toBeTrue()->and($factura->anulada_por)->toBe($this->cajero->id)->and($factura->motivo_anulacion_id)->toBe($this->otroMotivo->id)
        ->and($factura->detalle_anulacion)->toBe('Se cargó a otra paciente')->and($factura->numero_completo)->toBe('001-001-0000001')
        ->and($cobro->revertido())->toBeTrue()->and($cobro->caja_reversion_id)->toBe($caja->id)
        ->and($cobro->motivo_reversion)->toBe('Anulación de la factura 001-001-0000001. Motivo: Otro (detallar) (Se cargó a otra paciente)')
        ->and(MovimientoCaja::where('caja_id', $caja->id)->where('tipo', MovimientoCaja::REVERSION)->sum('monto'))->toEqual(-100000)
        ->and($caja->fresh()->esperadoPorMedio()[$this->efectivo->id])->toBe(500000)
        ->and($prestacion->fresh()->estadoCobroTexto())->toBe(Prestacion::PENDIENTE_DE_COBRO);
    // Una anulada no se anula de nuevo.
    facturacionAnular($this->cajero, $factura)->assertSessionHas('error', AnularFactura::YA_ANULADA);
});

test('pasado el plazo, de otro cajero o con la caja cerrada, solo por solicitud; Gerencia la aprueba y el dinero sale de la caja del solicitante', function () {
    facturacionTimbrado();
    $caja = facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    [$primera, $segunda] = Factura::orderBy('id')->get()->all();

    // De otro cajero: no la anula directo.
    $cajaOtro = facturacionAbrir($this->otroCajero);
    facturacionAnular($this->otroCajero, $primera)->assertSessionHas('error', AnularFactura::necesitaSolicitud());
    // Pasado el plazo: tampoco el suyo.
    Carbon::setTestNow(now()->addMinutes(11));
    facturacionAnular($this->cajero, $primera)->assertSessionHas('error', AnularFactura::necesitaSolicitud());
    $this->actingAs($this->cajero)->get(route('admin.facturas.show', $primera))->assertOk()->assertSee('Solicitar la anulación')->assertDontSee('Anular la factura');
    facturacionSolicitar($this->cajero, $primera)->assertSessionHas('status');
    // Una sola pendiente por factura.
    facturacionSolicitar($this->otroCajero, $primera)->assertSessionHas('error', AnularFactura::YA_SOLICITADA);

    $this->actingAs($this->gerente)->get(route('admin.facturas.solicitudes'))->assertOk()->assertSee('001-001-0000001');
    $this->actingAs($this->gerente)->post(route('admin.facturas.solicitudes.aprobar', SolicitudAnulacionFactura::sole()), ['nota' => 'Verificado'])->assertSessionHas('status');
    expect($primera->fresh()->anulada())->toBeTrue()->and($primera->fresh()->anulada_por)->toBe($this->gerente->id)
        ->and(Cobro::where('factura_id', $primera->id)->sole()->caja_reversion_id)->toBe($caja->id);

    // Con la caja cerrada: solo por solicitud; al aprobarse, el dinero sale de la caja abierta de hoy del solicitante.
    facturacionCerrar($this->cajero, $caja);
    Carbon::setTestNow(now()->subMinutes(11));
    facturacionAnular($this->cajero, $segunda)->assertSessionHas('error', AnularFactura::necesitaSolicitud());
    facturacionSolicitar($this->otroCajero, $segunda)->assertSessionHas('status');
    $cerrada = DB::table('movimientos_caja')->where('caja_id', $caja->id)->count();
    $this->actingAs($this->gerente)->post(route('admin.facturas.solicitudes.aprobar', SolicitudAnulacionFactura::pendientes()->sole()))->assertSessionHas('status');
    expect(Cobro::where('factura_id', $segunda->id)->sole()->caja_reversion_id)->toBe($cajaOtro->id)
        ->and(DB::table('movimientos_caja')->where('caja_id', $caja->id)->count())->toBe($cerrada);
});

test('rechazo con nota y nadie aprueba ni rechaza su propia solicitud', function () {
    facturacionTimbrado();
    facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    $factura = Factura::sole();
    Carbon::setTestNow(now()->addMinutes(15));
    $solicitud = AnularFactura::solicitar($this->cajero, $factura, $this->datosCliente->id, null);

    expect(fn () => AnularFactura::aprobar($this->cajero, $solicitud))->toThrow(AccionRechazada::class, AnularFactura::PROPIA)
        ->and(fn () => AnularFactura::rechazar($this->cajero, $solicitud, 'No corresponde'))->toThrow(AccionRechazada::class, AnularFactura::PROPIA);
    // Gerencia la rechaza con nota: la factura y su cobro siguen vigentes.
    $this->actingAs($this->gerente)->post(route('admin.facturas.solicitudes.rechazar', $solicitud), ['nota_rechazo' => 'La factura es correcta'])->assertSessionHas('status');
    expect($solicitud->fresh()->tieneEstado(Estado::RECHAZADO))->toBeTrue()->and($solicitud->fresh()->nota_revision)->toBe('La factura es correcta')
        ->and($factura->fresh()->anulada())->toBeFalse()->and(Cobro::sole()->revertido())->toBeFalse();

    // Rechazada, se puede volver a pedir; el Administrador tampoco aprueba la suya.
    $admin = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->sole()])->create();
    $propia = AnularFactura::solicitar($admin, $factura, $this->datosCliente->id, null);
    $this->actingAs($admin)->post(route('admin.facturas.solicitudes.aprobar', $propia))->assertSessionHas('error', AnularFactura::PROPIA);
    expect($propia->fresh()->pendiente())->toBeTrue()->and($factura->fresh()->anulada())->toBeFalse();
});

test('los cobros anteriores a la facturación no tienen factura, no se facturan y siguen revirtiéndose como antes; los nuevos solo anulando la factura', function () {
    facturacionTimbrado();
    // Antes del hito: un cobro sin factura.
    DB::table('hitos_sistema')->where('codigo', EmitirFactura::HITO)->update(['fecha_hora' => '2026-10-06 13:00:00']);
    $caja = facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHas('status', 'Cobro registrado: ₲ 100.000.');
    $anterior = Cobro::sole();
    expect($anterior->factura_id)->toBeNull()->and(Factura::count())->toBe(0);
    $this->actingAs($this->cajero)->get(route('admin.cobros.show', $anterior))->assertOk()->assertSee('Sin factura (anterior a la facturación)')->assertSee('Revertir el cobro');

    // Desde el hito: el cobro nuevo lleva factura y el anterior sigue sin ella.
    Carbon::setTestNow(Carbon::parse('2026-10-06 13:05:00', 'UTC'));
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    $nuevo = Cobro::latest('id')->first();
    expect($nuevo->factura_id)->not->toBeNull()->and($anterior->fresh()->factura_id)->toBeNull()->and(Factura::count())->toBe(1);

    // El anterior se revierte con las reglas de la fase 3b; el nuevo, no (solo anulando su factura).
    RevertirCobro::ejecutar($this->cajero, $anterior, 'Cobro cargado por error, motivo inventado.');
    expect($anterior->fresh()->revertido())->toBeTrue()
        ->and(fn () => RevertirCobro::ejecutar($this->cajero, $nuevo, 'Cobro cargado por error, motivo inventado.'))->toThrow(AccionRechazada::class, RevertirCobro::CON_FACTURA)
        ->and(fn () => RevertirCobro::solicitar($this->cajero, $nuevo, 'Cobro cargado por error, motivo inventado.'))->toThrow(AccionRechazada::class, RevertirCobro::CON_FACTURA);
    $this->actingAs($this->cajero)->get(route('admin.cobros.show', $nuevo))->assertOk()->assertDontSee('Revertir el cobro')
        ->assertSee('Este cobro tiene factura: se revierte anulando la factura');
});

test('el PDF de la factura: con permiso sale al vuelo con la marca de simulación y queda en Auditoría; sin permiso, no', function () {
    facturacionTimbrado();
    facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    $factura = Factura::sole();

    $html = PdfFactura::html($factura);
    expect($html)->toContain('SIMULACIÓN, SIN VALIDEZ FISCAL')->toContain('001-001-0000001')->toContain('Timbrado N.º 12345678')
        ->toContain('Vigencia: 01/10/2026 al 30/09/2027')->toContain('CONTADO')->toContain('Carmen Duarte')->toContain('RUC: '.Ruc::conDigito('80999001'))
        ->toContain('₲ 9.091')->not->toContain('Rinofaringitis');

    $respuesta = $this->actingAs($this->cajero)->get(route('admin.facturas.pdf', $factura))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store');
    $this->actingAs(facturacionUsuario(PerfilesPredefinidos::FACTURACION))->get(route('admin.facturas.pdf', $factura))->assertOk();
    expect(LogAuditoria::where('tabla_afectada', 'facturas')->where('accion', AccionAuditoria::EXPORTAR->value)->pluck('detalle')->all())
        ->toBe(['PDF de la factura 001-001-0000001', 'PDF de la factura 001-001-0000001 (copia)']);
    expect(PdfFactura::html($factura, copia: true))->toContain('FACTURA (COPIA)');

    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::AUDITOR] as $perfil) {
        $this->actingAs(facturacionUsuario($perfil))->get(route('admin.facturas.pdf', $factura))->assertForbidden();
    }
    // Sin la simulación la factura nueva no lleva la marca... y sin emisor real no se emite.
    config(['clinexa.facturacion.simulada' => false]);
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHas('error');
    expect(Factura::count())->toBe(1)->and(PdfFactura::html($factura))->toContain('SIMULACIÓN, SIN VALIDEZ FISCAL');
});

test('al cobrar se redirige a la factura con el PDF embebido; recargarla no cobra ni emite nada y la numeración no cambia', function () {
    facturacionTimbrado();
    facturacionAbrir($this->cajero);
    $respuesta = facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    $factura = Factura::sole();
    $respuesta->assertRedirect(route('admin.facturas.imprimir', $factura))->assertSessionHas('status', 'Factura 001-001-0000001 emitida y cobrada: ₲ 100.000.');

    $pagina = $this->actingAs($this->cajero)->get(route('admin.facturas.imprimir', $factura))->assertOk()
        ->assertSee(route('admin.facturas.pdf', $factura), false)->assertSee('<iframe', false)->assertSeeText('Imprimir')
        ->assertSeeText('Nuevo cobro')->assertSeeText('Ver detalle de la factura');
    expect($pagina->headers->get('Cache-Control'))->toContain('no-store');
    $this->get(route('admin.facturas.imprimir', $factura))->assertOk();
    expect(Factura::count())->toBe(1)->and(Cobro::count())->toBe(1)->and(Factura::sole()->numero_completo)->toBe('001-001-0000001');

    // La pantalla no genera el PDF: el EXPORTAR queda solo cuando el navegador lo pide.
    expect(LogAuditoria::where('tabla_afectada', 'facturas')->where('accion', AccionAuditoria::EXPORTAR->value)->count())->toBe(0);
    $this->get(route('admin.facturas.pdf', $factura))->assertOk();
    expect(LogAuditoria::where('tabla_afectada', 'facturas')->where('accion', AccionAuditoria::EXPORTAR->value)->count())->toBe(1);

    // El siguiente cobro sigue la numeración.
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertRedirect(route('admin.facturas.imprimir', Factura::latest('id')->first()));
    expect(Factura::latest('id')->first()->numero_completo)->toBe('001-001-0000002');
});

test('sin EXPORTAR sobre FACTURAS no se embebe el PDF; el botón "Imprimir factura" aparece en los detalles de la factura y del cobro', function () {
    facturacionTimbrado();
    facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    $factura = Factura::sole();

    $this->actingAs($this->cajero)->get(route('admin.facturas.show', $factura))->assertSeeText('Imprimir factura')->assertSee(route('admin.facturas.imprimir', $factura), false);
    $this->get(route('admin.cobros.show', $factura->cobro))->assertSeeText('Imprimir factura')->assertSee(route('admin.facturas.imprimir', $factura), false);
    $this->get(route('admin.facturas.index'))->assertDontSeeText('Cada cobro en Caja emite su factura');

    // Sin EXPORTAR (Recepción no tiene FACTURAS): ni la pantalla ni el botón.
    $this->actingAs(facturacionUsuario(PerfilesPredefinidos::RECEPCION))->get(route('admin.facturas.imprimir', $factura))->assertForbidden();

    // Un cajero sin EXPORTAR cobra y cae en el detalle del cobro, sin PDF embebido ni botón.
    $sinPdf = facturacionUsuario(PerfilesPredefinidos::CAJA);
    DB::table('perfil_permiso')->whereIn('perfil_acceso_id', $sinPdf->perfilesActivos()->modelKeys())
        ->whereIn('permiso_id', Permiso::where('accion', 'EXPORTAR')->whereHas('moduloSistema', fn ($q) => $q->where('codigo', 'FACTURAS'))->pluck('id'))->delete();
    $sinPdf = $sinPdf->fresh();
    $this->actingAs($sinPdf)->get(route('admin.facturas.imprimir', $factura))->assertForbidden();
    $this->get(route('admin.facturas.show', $factura))->assertOk()->assertDontSeeText('Imprimir factura');
    $this->get(route('admin.cobros.show', $factura->cobro))->assertOk()->assertDontSeeText('Imprimir factura');
    facturacionAbrir($this->otroCajero);
    facturacionCobrar($this->otroCajero, [facturacionPrestacion()])->assertRedirect(route('admin.cobros.show', Cobro::latest('id')->first()));
});

test('permisos: Caja emite, anula y solicita; Gerencia aprueba y administra timbrados, clínica y tasas; Facturación ve y saca el PDF; los demás, nada', function () {
    facturacionTimbrado();
    facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    $factura = Factura::sole();
    $facturacion = facturacionUsuario(PerfilesPredefinidos::FACTURACION);

    $this->actingAs($this->cajero)->get(route('admin.facturas.index'))->assertOk()->assertSee('001-001-0000001');
    $this->get(route('admin.facturas.timbrados.create'))->assertForbidden();
    $this->get(route('admin.clinica.edit'))->assertForbidden();
    $this->get(route('admin.facturas.solicitudes'))->assertForbidden();

    $this->actingAs($this->gerente)->get(route('admin.facturas.show', $factura))->assertOk()->assertDontSee('Anular la factura')->assertDontSee('Solicitar la anulación');
    $this->get(route('admin.facturas.timbrados.create'))->assertOk()
        ->assertSee('<form method="POST" action="'.route('admin.facturas.timbrados.store').'" novalidate', false);
    foreach (['admin.facturas.index', 'admin.facturas.solicitudes', 'admin.facturas.timbrados', 'admin.facturas.tasas-iva', 'admin.facturas.motivos'] as $pestana) {
        $this->get(route($pestana))->assertOk()->assertSee('Solicitudes de anulación (0)')->assertSee('Tasas de IVA')->assertSee('SIMULACIÓN, SIN VALIDEZ FISCAL');
    }
    $this->get(route('admin.facturas.motivos.create'))->assertOk()
        ->assertSee('<form method="POST" action="'.route('admin.facturas.motivos.store').'" novalidate', false);
    $this->get(route('admin.clinica.edit'))->assertOk()->assertSee('Clínica de Prueba S.A.');
    $this->patch(route('admin.facturas.tasas-iva.update', $this->eco), ['tasa_iva' => 5])->assertSessionHas('status');
    expect($this->eco->fresh()->tasa_iva)->toBe(5);
    $this->post(route('admin.facturas.anular', $factura), ['motivo_anulacion_id' => $this->datosCliente->id])->assertForbidden();
    $this->post(route('admin.cobros.store'), [])->assertForbidden();

    $this->actingAs($facturacion)->get(route('admin.facturas.show', $factura))->assertOk();
    $this->post(route('admin.facturas.solicitar-anulacion', $factura), ['motivo_anulacion_id' => $this->datosCliente->id])->assertForbidden();
    $this->get(route('admin.facturas.timbrados'))->assertForbidden();
    $this->patch(route('admin.facturas.tasas-iva.update', $this->eco), ['tasa_iva' => 0])->assertForbidden();

    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA, PerfilesPredefinidos::COMPRAS_TESORERIA] as $perfil) {
        $usuario = facturacionUsuario($perfil);
        foreach (['FACTURAS', 'ANULACIONES_FACTURA', 'TIMBRADOS', 'DATOS_FISCALES', 'MOTIVOS_ANULACION'] as $modulo) {
            expect($usuario->tienePermiso($modulo, 'VER'))->toBeFalse("{$perfil} {$modulo}");
        }
        $this->actingAs($usuario)->get(route('admin.facturas.index'))->assertForbidden();
        $this->get(route('admin.facturas.show', $factura))->assertForbidden();
    }
    // La prestación muestra la factura a quien ve las facturas; a Recepción, solo el número.
    $this->actingAs(facturacionUsuario(PerfilesPredefinidos::RECEPCION))->get(route('admin.prestaciones.show', $factura->detalles()->sole()->prestacion_id))
        ->assertOk()->assertSee('001-001-0000001')->assertDontSee(route('admin.facturas.show', $factura));
});

test('auditoría: emisión, anulación, solicitud y PDF; los montos se ocultan a quien no ve las facturas', function () {
    facturacionTimbrado();
    facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    [$primera, $segunda] = Factura::orderBy('id')->get()->all();
    facturacionAnular($this->cajero, $primera)->assertSessionHas('status');
    Carbon::setTestNow(now()->addMinutes(20));
    facturacionSolicitar($this->cajero, $segunda, 'Dato mal cargado')->assertSessionHas('status');
    $this->actingAs($this->cajero)->get(route('admin.facturas.pdf', $segunda))->assertOk();

    $log = fn (string $tabla, AccionAuditoria $accion = AccionAuditoria::CREAR) => LogAuditoria::where('tabla_afectada', $tabla)->where('accion', $accion->value)->orderBy('id')->get();
    expect($log('facturas')->pluck('detalle')->all())->toBe(['Factura 001-001-0000001', 'Factura 001-001-0000002'])
        ->and($log('detalles_factura')->pluck('detalle')->all())->toBe(['Factura 001-001-0000001', 'Factura 001-001-0000002'])
        ->and($log('facturas', AccionAuditoria::EDITAR)->pluck('detalle')->all())->toBe(['Factura 001-001-0000001 cobrada', 'Factura 001-001-0000002 cobrada'])
        ->and($log('facturas', AccionAuditoria::ANULAR)->sole()->detalle)->toBe('Factura 001-001-0000001 anulada. Motivo: Error en los datos del cliente')
        ->and($log('detalles_factura', AccionAuditoria::EDITAR)->sole()->detalle)->toBe('Liberada por la anulación de la factura 001-001-0000001')
        ->and($log('cobros', AccionAuditoria::REVERTIR)->sole()->detalle)->toBe('Reversión del cobro. Motivo: Anulación de la factura 001-001-0000001. Motivo: Error en los datos del cliente')
        ->and($log('solicitudes_anulacion_factura')->sole()->detalle)->toBe('Solicitud de anulación de la factura 001-001-0000002')
        ->and($log('facturas', AccionAuditoria::EXPORTAR)->sole()->detalle)->toBe('PDF de la factura 001-001-0000002');
    foreach (['facturas', 'detalles_factura', 'solicitudes_anulacion_factura'] as $tabla) {
        expect(Auditoria::permisoDeContenido($tabla))->toBe('FACTURAS');
    }

    // El Auditor ve los eventos sin los montos; Gerencia los ve.
    $this->actingAs(facturacionUsuario(PerfilesPredefinidos::AUDITOR))->get(route('admin.auditoria.show', $log('facturas')->first()))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Facturas')->assertDontSee('90909');
    $this->actingAs($this->gerente)->get(route('admin.auditoria.show', $log('facturas')->first()))->assertOk()->assertDontSee('Contenido económico')->assertSee('90909');
});

test('facturas, líneas, timbrados y solicitudes no se editan ni se borran', function () {
    $timbrado = facturacionTimbrado();
    facturacionAbrir($this->cajero);
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    $factura = Factura::sole();

    expect(fn () => $factura->update(['total' => 1]))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $factura->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $factura->detalles()->sole()->update(['monto' => 1]))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $timbrado->fresh()->update(['numero_final' => 5]))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $timbrado->fresh()->update(['numero_actual' => 5]))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $timbrado->fresh()->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $this->datosCliente->delete())->toThrow(AccionRechazada::class)
        ->and(fn () => Clinica::actual()->delete())->toThrow(AccionRechazada::class);
});

test('los timbrados: Gerencia carga uno nuevo para el mismo punto (el anterior pasa a histórico) o lo pasa a histórico; uno vigente por punto', function () {
    $primero = facturacionTimbrado();
    $this->actingAs($this->gerente)->post(route('admin.facturas.timbrados.store'), [
        'sucursal_id' => Sucursal::principal()->id, 'numero' => '87654321', 'vigente_desde' => '01/11/2026', 'vigente_hasta' => '31/10/2027',
        'establecimiento' => '001', 'punto_expedicion' => '001', 'numero_inicial' => '1', 'numero_final' => '500',
    ])->assertSessionHas('status');
    $segundo = Timbrado::latest('id')->first();
    expect($primero->fresh()->historico())->toBeTrue()->and($segundo->historico())->toBeFalse()->and($segundo->numero_actual)->toBe(0)
        ->and($segundo->vigente_desde->format('Y-m-d'))->toBe('2026-11-01');

    // Todavía no rige: no se emite con él.
    expect(EmitirFactura::timbradoParaEmitir()[1])->toBe('El timbrado N.º 87654321 rige desde el 01/11/2026: todavía no se puede emitir con él.');
    // Uno vigente por sucursal, establecimiento y punto, aunque algo se saltee el servicio.
    expect(fn () => DB::table('timbrados')->insert([...collect(DB::table('timbrados')->where('id', $segundo->id)->first())->except('id')->all()]))
        ->toThrow(QueryException::class);
    // Otro punto vigente en la misma sucursal: no se emite hasta dejar uno solo.
    GestionTimbrados::cargar($this->gerente, ['sucursal_id' => Sucursal::principal()->id, 'numero' => '11112222', 'vigente_desde' => '2026-10-01',
        'vigente_hasta' => '2027-09-30', 'establecimiento' => '001', 'punto_expedicion' => '002', 'numero_inicial' => 1, 'numero_final' => 100]);
    expect(EmitirFactura::timbradoParaEmitir()[1])->toBe(EmitirFactura::VARIOS_PUNTOS);

    $this->actingAs($this->gerente)->post(route('admin.facturas.timbrados.historico', $segundo))->assertSessionHas('status');
    expect($segundo->fresh()->historico())->toBeTrue()->and(EmitirFactura::timbradoParaEmitir()[0]?->numero)->toBe('11112222')
        ->and(LogAuditoria::where('tabla_afectada', 'timbrados')->where('registro_afectado_id', (string) $segundo->id)->orderBy('id')->pluck('detalle')->all())
        ->toBe(['Alta del timbrado N.º 87654321', 'Timbrado pasado a histórico'])
        ->and(LogAuditoria::where('tabla_afectada', 'timbrados')->where('registro_afectado_id', (string) $primero->id)->pluck('detalle')->all())
        ->toBe(['Reemplazado por el timbrado N.º 87654321']);
    $this->get(route('admin.facturas.timbrados'))->assertOk()->assertSee('87654321')->assertSee('Histórico');
});

test('los datos de la clínica: Gerencia los carga con el RUC validado y las sucursales pasan a ser suyas; sin ellos, el aviso', function () {
    expect(Clinica::faltaParaEmitir(null))->toContain('Faltan los datos fiscales de la clínica');
    $datos = ['tipo_contribuyente' => Clinica::UNIPERSONAL, 'razon_social' => 'Rosa Benítez (prueba)', 'ruc' => '80999001-1', // el dígito correcto es 6
        'actividad_economica' => 'Consultorio médico (prueba)', 'direccion_fiscal' => 'Calle Inventada 456'];

    $this->actingAs($this->gerente)->from(route('admin.clinica.edit'))->put(route('admin.clinica.update'), $datos)->assertSessionHasErrors('ruc');
    $this->put(route('admin.clinica.update'), [...$datos, 'ruc' => Ruc::conDigito('80999001')])->assertSessionHasNoErrors();

    $clinica = Clinica::sole();
    expect($clinica->tipo_contribuyente)->toBe(Clinica::UNIPERSONAL)->and(Sucursal::whereNull('clinica_id')->count())->toBe(0)
        ->and(Clinica::faltaParaEmitir($clinica))->toBeNull()
        ->and(LogAuditoria::where('tabla_afectada', 'clinicas')->where('accion', AccionAuditoria::CREAR->value)->count())->toBe(1);
    // Una sola por instalación.
    expect(fn () => DB::table('clinicas')->insert([...collect(DB::table('clinicas')->first())->except('id')->merge(['ruc' => '80999002-4'])->all()]))
        ->toThrow(QueryException::class);
});

test('mostrar_marca controla solo la marca: apagada no aparece en pantalla ni en el PDF y se emite y anula; encendida aparece', function () {
    facturacionTimbrado();
    facturacionAbrir($this->cajero);
    config(['clinexa.facturacion.mostrar_marca' => false]);
    facturacionCobrar($this->cajero, [facturacionPrestacion()])->assertSessionHasNoErrors();
    $factura = Factura::sole();
    expect($factura->simulada)->toBeTrue()->and(PdfFactura::html($factura))->not->toContain('SIN VALIDEZ FISCAL');
    $this->actingAs($this->cajero)->get(route('admin.facturas.index'))->assertOk()->assertDontSee('SIN VALIDEZ FISCAL');
    $this->get(route('admin.facturas.show', $factura))->assertOk()->assertDontSee('SIN VALIDEZ FISCAL');
    $this->get(route('admin.cobros.create'))->assertDontSee('SIN VALIDEZ FISCAL');
    $this->get(route('admin.facturas.pdf', $factura))->assertOk();

    facturacionAnular($this->cajero, $factura)->assertSessionHasNoErrors();
    expect($factura->fresh()->anulada())->toBeTrue();

    config(['clinexa.facturacion.mostrar_marca' => true]);
    expect(PdfFactura::html($factura))->toContain('SIMULACIÓN, SIN VALIDEZ FISCAL');
    $this->get(route('admin.facturas.show', $factura))->assertSee('SIN VALIDEZ FISCAL');
});
