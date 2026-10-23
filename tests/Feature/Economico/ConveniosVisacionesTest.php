<?php

/*
 * Convenios y visaciones (docs/convenios-seguros.md): la visación con cobertura total, con porcentaje y con monto fijo; la línea no
 * cubierta (paga la general completa); el procedimiento sin visar (particular con aviso); sin cobertura o vencida; la tarifa del
 * convenio y el respaldo a la general; la suma exacta con redondeo; el snapshot que no cambia; el informe de estudio con visación; el
 * número duplicado; el cobro solo de la parte de la paciente y la parte cero fuera del cobro; la reversión; aplicar visación tarde (y
 * bloqueada con cobro); la firma; el ticket; el reparto sobre el total; permisos y auditoría. Datos, precios y números inventados.
 * Prefijo "conveniosVisaciones".
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\AcuerdoReparto;
use App\Models\CoberturaPaciente;
use App\Models\Cobro;
use App\Models\CobroPrestacion;
use App\Models\Consulta;
use App\Models\Convenio;
use App\Models\Estado;
use App\Models\LineaReparto;
use App\Models\LineaVisacion;
use App\Models\LogAuditoria;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\RepartoPrestacion;
use App\Models\ResponsablePago;
use App\Models\Turno;
use App\Models\User;
use App\Models\Visacion;
use App\Support\Auditoria;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\RevertirCobro;
use App\Support\Convenios\AplicarVisacion;
use App\Support\Convenios\CalcularCobertura;
use App\Support\Convenios\GestionConvenios;
use App\Support\Convenios\GestionCoberturas;
use App\Support\Convenios\TicketVisacion;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\AltaAcuerdo;
use App\Support\Repartos\Porcentaje;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    hcEscenario(); // "hoy": martes 06/10/2026, 09:00 en Paraguay
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $this->consultaProc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->eco = Procedimiento::create(['codigo' => 'ECO-PRUEBA', 'nombre' => 'Ecografía de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);
    $this->rx = Procedimiento::create(['codigo' => 'RX-PRUEBA', 'nombre' => 'Radiografía de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 15]);
    $this->gerente = conveniosVisacionesUsuario(PerfilesPredefinidos::GERENCIA);
    $this->recepcion = conveniosVisacionesUsuario(PerfilesPredefinidos::RECEPCION);
    $this->facturacion = conveniosVisacionesUsuario(PerfilesPredefinidos::FACTURACION);
    $this->cajero = conveniosVisacionesUsuario(PerfilesPredefinidos::CAJA);
    // Las migraciones guardaron la hora real en que corrieron; el "hoy" de estos tests es anterior.
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO, CalcularCobertura::HITO])
        ->update(['fecha_hora' => '2026-10-01 00:00:00']);

    AltaTarifa::ejecutar($this->gerente, $this->consultaProc->id, null, 100000, Carbon::parse('2026-10-01'));
    AltaTarifa::ejecutar($this->gerente, $this->eco->id, null, 300000, Carbon::parse('2026-10-01'));
    AltaAcuerdo::ejecutar($this->gerente, AcuerdoReparto::PROFESIONAL, $this->profesional->id, null, null, Porcentaje::desdeTexto('40'), Carbon::parse('2026-10-01'));

    $this->aseguradora = conveniosVisacionesResponsable('Seguros de Prueba');
    $this->convenio = GestionConvenios::alta($this->facturacion, $this->aseguradora->id, 'Convenio de prueba', Carbon::parse('2026-10-01'), null, ['Plan Oro', 'Plan Plata']);
    $this->plan = $this->convenio->planes()->where('nombre', 'Plan Oro')->sole();
    // La ecografía figura en el convenio; la consulta no (usa la general).
    GestionConvenios::guardarTarifa($this->facturacion, $this->convenio, $this->eco->id, 250000, Carbon::parse('2026-10-01'));
    $this->efectivo = MedioPago::where('codigo', MedioPago::EFECTIVO)->sole();
});

afterEach(fn () => Carbon::setTestNow());

function conveniosVisacionesUsuario(string $codigo): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
}

function conveniosVisacionesResponsable(string $nombre): ResponsablePago
{
    return ResponsablePago::create(['persona_id' => Persona::factory()->create(['apellidos' => $nombre, 'nombres' => 'S.A.'])->id]);
}

/** Recepción carga la cobertura de Carmen Duarte por la pantalla (desde el 01/10/2026, o lo indicado). */
function conveniosVisacionesCobertura(array $cambios = [])
{
    return test()->actingAs(test()->recepcion)->from(route('admin.coberturas.show', test()->paciente))->post(route('admin.coberturas.store', test()->paciente), [
        'plan_convenio_id' => test()->plan->id, 'numero_asegurado' => 'AS-0001', 'tipo_afiliado' => 'TITULAR', 'vigente_desde' => '01/10/2026', 'vigente_hasta' => '',
        ...$cambios,
    ]);
}

/**
 * Recepción carga una visación por la pantalla para el turno (o la consulta) con esas líneas: [procedimiento, resultado, copago, valor].
 */
function conveniosVisacionesVisacion(Turno|Consulta $destino, array $lineas, string $numero = 'V-1001')
{
    return test()->actingAs(test()->recepcion)->from(route('admin.coberturas.show', test()->paciente))->post(route('admin.visaciones.store', test()->paciente), [
        'destino' => ($destino instanceof Turno ? 'turno:' : 'consulta:').$destino->id, 'numero' => $numero, 'fecha' => '06/10/2026',
        'lineas' => array_map(fn (array $linea) => ['procedimiento_id' => $linea[0]->id, 'resultado' => $linea[1], 'copago' => $linea[2] ?? '', 'valor' => $linea[3] ?? ''], $lineas),
    ]);
}

/** La Dra. Benítez atiende el turno (o sin turno) y finaliza, con informes de estudio si se indican. La prestación base. */
function conveniosVisacionesAtender(?Turno $turno, array $informes = []): Prestacion
{
    test()->actingAs(test()->medico);
    $datos = $informes === [] ? [] : ['con_informes' => '1', 'informes' => collect($informes)->values()->map(fn (Procedimiento $procedimiento, int $i) => [
        'uid' => "n-{$i}", 'id' => '', 'activo' => '1', 'procedimiento_id' => (string) $procedimiento->id, 'texto' => 'Informe inventado '.($i + 1).'.',
    ])->all()];
    hcGuardarNueva($datos, $turno)->assertSessionHasNoErrors();

    return Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->whereNull('informe_estudio_id')->sole();
}

function conveniosVisacionesTurnoEco(): Turno
{
    return hcTurno(['procedimiento_id' => test()->eco->id]);
}

/** Las líneas del reparto vigente: beneficiario => monto. */
function conveniosVisacionesReparto(Prestacion $prestacion): array
{
    return LineaReparto::whereHas('reparto', fn ($query) => $query->where('prestacion_id', $prestacion->id)->whereNull('anulado_en'))->orderBy('id')->get()
        ->mapWithKeys(fn (LineaReparto $linea) => [$linea->beneficiario => (int) $linea->monto])->all();
}

function conveniosVisacionesAbrirCaja(): void
{
    test()->actingAs(test()->cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => '0'])->assertSessionHasNoErrors();
}

function conveniosVisacionesCobrar(array $prestaciones, int $monto)
{
    return test()->actingAs(test()->cajero)->from(route('admin.cobros.create'))->post(route('admin.cobros.store'), [
        'paciente_id' => test()->paciente->id,
        'prestaciones' => array_map(fn (Prestacion $prestacion) => $prestacion->id, $prestaciones),
        'lineas' => [['medio_pago_id' => test()->efectivo->id, 'monto' => (string) $monto, 'referencia' => '']],
    ]);
}

test('cobertura total: tarifa del convenio, todo a la aseguradora, pendiente de liquidar y fuera del cobro; el reparto sobre el total', function () {
    conveniosVisacionesCobertura()->assertSessionHasNoErrors()->assertSessionHas('status', 'Cobertura cargada.');
    $turno = conveniosVisacionesTurnoEco();
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]])->assertSessionHasNoErrors()->assertSessionHas('status', 'Visación N.º V-1001 cargada.');

    $prestacion = conveniosVisacionesAtender($turno);

    expect($prestacion->pagador)->toBe(Prestacion::ASEGURADORA)
        ->and($prestacion->responsable_pago_id)->toBe($this->aseguradora->id)->and($prestacion->convenio_id)->toBe($this->convenio->id)
        ->and($prestacion->plan_convenio_id)->toBe($this->plan->id)->and($prestacion->numero_visacion)->toBe('V-1001')
        ->and($prestacion->tarifa_id)->toBeNull()->and($prestacion->tarifa_convenio_id)->not->toBeNull()
        ->and((int) $prestacion->monto)->toBe(250000)->and((int) $prestacion->monto_paciente)->toBe(0)->and((int) $prestacion->monto_aseguradora)->toBe(250000)
        ->and($prestacion->liquidacion)->toBe(Prestacion::PENDIENTE_DE_LIQUIDAR)->and($prestacion->aviso_cobertura)->toBeNull()
        ->and($prestacion->estadoCobroTexto())->toBe(Prestacion::A_CARGO_DE_ASEGURADORA)
        ->and(Cobrar::pendientes()->whereKey($prestacion->id)->exists())->toBeFalse()
        // El reparto no cambia por quién paga: sobre el total (40 % la profesional).
        ->and(conveniosVisacionesReparto($prestacion))->toBe(['CLINICA' => 150000, 'PROFESIONAL' => 100000]);
});

test('copago por porcentaje con redondeo al guaraní: la suma es exacta y el resto va a la aseguradora', function () {
    GestionConvenios::guardarTarifa($this->facturacion, $this->convenio, $this->consultaProc->id, 150005, Carbon::parse('2026-10-01'));
    conveniosVisacionesCobertura();
    $turno = hcTurno(['procedimiento_id' => $this->consultaProc->id]);
    conveniosVisacionesVisacion($turno, [[$this->consultaProc, LineaVisacion::APROBADO, LineaVisacion::PORCENTAJE, '10']])->assertSessionHasNoErrors();

    $prestacion = conveniosVisacionesAtender($turno);

    // 10 % de 150.005 = 15.000,5: a la paciente 15.001 (la mitad, hacia arriba) y a la aseguradora el resto.
    expect((int) $prestacion->monto)->toBe(150005)->and((int) $prestacion->monto_paciente)->toBe(15001)->and((int) $prestacion->monto_aseguradora)->toBe(135004)
        ->and((int) $prestacion->monto_paciente + (int) $prestacion->monto_aseguradora)->toBe((int) $prestacion->monto)
        ->and($prestacion->estadoCobroTexto())->toBe(Prestacion::PENDIENTE_DE_COBRO);
});

test('copago de monto fijo, que nunca pasa de la tarifa; sin tarifa del convenio se usa la general como respaldo', function () {
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::MONTO_FIJO, '100.000']]);
    $eco = conveniosVisacionesAtender($turno);
    expect((int) $eco->monto)->toBe(250000)->and((int) $eco->monto_paciente)->toBe(100000)->and((int) $eco->monto_aseguradora)->toBe(150000);

    // La consulta no figura en el convenio: la tarifa general (100.000); el copago de 500.000 se recorta al total.
    $otroTurno = hcTurno(['procedimiento_id' => $this->consultaProc->id, 'hora_inicio' => '10:00', 'hora_fin' => '10:30']);
    conveniosVisacionesVisacion($otroTurno, [[$this->consultaProc, LineaVisacion::APROBADO, LineaVisacion::MONTO_FIJO, '500000']], 'V-2002');
    $consulta = conveniosVisacionesAtender($otroTurno);
    expect($consulta->tarifa_id)->not->toBeNull()->and($consulta->tarifa_convenio_id)->toBeNull()->and($consulta->pagador)->toBe(Prestacion::ASEGURADORA)
        ->and((int) $consulta->monto)->toBe(100000)->and((int) $consulta->monto_paciente)->toBe(100000)->and((int) $consulta->monto_aseguradora)->toBe(0)
        // Nada que liquidar con la aseguradora.
        ->and($consulta->liquidacion)->toBeNull();
});

test('línea no cubierta: la paciente paga la tarifa general completa y la aseguradora no interviene', function () {
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::NO_CUBIERTO]])->assertSessionHasNoErrors();

    $prestacion = conveniosVisacionesAtender($turno);

    expect($prestacion->pagador)->toBe(Prestacion::PACIENTE)->and($prestacion->responsable_pago_id)->toBeNull()->and($prestacion->convenio_id)->toBeNull()
        ->and($prestacion->numero_visacion)->toBe('V-1001')->and($prestacion->tarifa_convenio_id)->toBeNull()
        ->and((int) $prestacion->monto)->toBe(300000)->and((int) $prestacion->monto_paciente)->toBe(300000)->and((int) $prestacion->monto_aseguradora)->toBe(0)
        ->and($prestacion->liquidacion)->toBeNull()->and($prestacion->aviso_cobertura)->toBeNull();
});

test('procedimiento sin visar: queda particular con aviso, y la finalización no se frena', function () {
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    // La visación del turno cubre la consulta, no la ecografía.
    conveniosVisacionesVisacion($turno, [[$this->consultaProc, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]]);

    $prestacion = conveniosVisacionesAtender($turno);

    expect($prestacion->consulta->tieneEstado(Estado::FINALIZADO))->toBeTrue()
        ->and($prestacion->pagador)->toBe(Prestacion::PACIENTE)->and((int) $prestacion->monto)->toBe(300000)->and((int) $prestacion->monto_paciente)->toBe(300000)
        ->and($prestacion->aviso_cobertura)->toBe('La visación N.º V-1001 no incluye este procedimiento: queda particular.')
        ->and(CalcularCobertura::conAviso()->whereKey($prestacion->id)->exists())->toBeTrue();

    // Recepción ve el aviso en Prestaciones.
    $this->actingAs($this->recepcion)->get(route('admin.prestaciones.index'))->assertOk()->assertSee('Hay 1 prestación con aviso de cobertura.');
    $this->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertSee('no incluye este procedimiento');
});

test('sin cobertura: particular sin aviso; con la cobertura vencida o sin visación: particular con aviso', function () {
    $sinCobertura = conveniosVisacionesAtender(null);
    expect($sinCobertura->pagador)->toBe(Prestacion::PACIENTE)->and($sinCobertura->aviso_cobertura)->toBeNull()->and((int) $sinCobertura->monto_paciente)->toBe(100000);

    conveniosVisacionesCobertura()->assertSessionHasNoErrors();
    $conCobertura = conveniosVisacionesAtender(null);
    expect($conCobertura->pagador)->toBe(Prestacion::PACIENTE)->and($conCobertura->aviso_cobertura)->toContain('tiene cobertura')->toContain('Plan Oro');

    GestionCoberturas::bajaCobertura($this->recepcion, CoberturaPaciente::sole(), Carbon::parse('2026-10-05'));
    $vencida = conveniosVisacionesAtender(null);
    expect($vencida->pagador)->toBe(Prestacion::PACIENTE)->and($vencida->aviso_cobertura)->toBe('La cobertura de la paciente venció el 05/10/2026: queda particular.');
});

test('una sola cobertura abierta por paciente: cargar otra cierra la vigente y queda el historial', function () {
    conveniosVisacionesCobertura()->assertSessionHasNoErrors();
    conveniosVisacionesCobertura(['numero_asegurado' => 'AS-0002', 'vigente_desde' => '06/10/2026'])->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Cobertura cambiada: la anterior quedó en el historial.');

    $coberturas = CoberturaPaciente::orderBy('id')->get();
    expect($coberturas)->toHaveCount(2)
        ->and($coberturas[0]->tieneEstado(Estado::HISTORICO))->toBeTrue()->and($coberturas[0]->vigente_hasta->format('Y-m-d'))->toBe('2026-10-05')
        ->and($coberturas[1]->abierta())->toBeTrue()->and($coberturas[1]->reemplaza_a_id)->toBe($coberturas[0]->id);
    // Antes del inicio de la vigente, no.
    conveniosVisacionesCobertura(['vigente_desde' => '02/10/2026'])->assertSessionHas('error');
    expect(fn () => $coberturas[1]->update(['numero_asegurado' => 'OTRO']))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $coberturas[1]->delete())->toThrow(RegistroEconomicoInmutable::class);
});

test('el snapshot no cambia aunque cambien la tarifa del convenio o se anule la visación (con aviso)', function () {
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::PORCENTAJE, '20']]);
    $prestacion = conveniosVisacionesAtender($turno);
    $antes = $prestacion->only(['monto', 'monto_paciente', 'monto_aseguradora', 'pagador', 'tarifa_convenio_id', 'numero_visacion']);

    GestionConvenios::guardarTarifa($this->facturacion, $this->convenio, $this->eco->id, 280000, Carbon::parse('2026-10-07'));
    $this->actingAs($this->recepcion)->post(route('admin.visaciones.anular', Visacion::sole()), ['motivo_anulacion' => 'Carga equivocada'])
        ->assertSessionHas('aviso');

    expect($prestacion->fresh()->only(array_keys($antes)))->toBe($antes)
        ->and(CalcularCobertura::conAviso()->whereKey($prestacion->id)->exists())->toBeTrue()
        ->and(fn () => $prestacion->fresh()->forceFill(['monto_paciente' => 0, 'monto_aseguradora' => 250000])->save())->toThrow(RegistroEconomicoInmutable::class);
    $this->get(route('admin.prestaciones.show', $prestacion))->assertSee('fue anulada');
});

test('informe de estudio con visación: su prestación usa la línea del estudio', function () {
    conveniosVisacionesCobertura();
    $turno = hcTurno(['procedimiento_id' => $this->consultaProc->id]);
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::MONTO_FIJO, '50000']]);

    $base = conveniosVisacionesAtender($turno, [$this->eco]);
    $informe = Prestacion::where('consulta_id', $base->consulta_id)->whereNotNull('informe_estudio_id')->sole();

    expect($informe->pagador)->toBe(Prestacion::ASEGURADORA)->and((int) $informe->monto)->toBe(250000)
        ->and((int) $informe->monto_paciente)->toBe(50000)->and((int) $informe->monto_aseguradora)->toBe(200000)
        // La consulta no está visada: particular, con aviso.
        ->and($base->pagador)->toBe(Prestacion::PACIENTE)->and($base->aviso_cobertura)->toContain('no incluye este procedimiento');
});

test('número de visación único por aseguradora entre las no anuladas', function () {
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]])->assertSessionHasNoErrors();

    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]])->assertSessionHas('error', GestionCoberturas::NUMERO_REPETIDO);
    expect(Visacion::count())->toBe(1);

    // Anulada, el número se puede volver a usar.
    GestionCoberturas::anularVisacion($this->recepcion, Visacion::sole(), 'Número mal cargado');
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]])->assertSessionHasNoErrors();
    expect(Visacion::count())->toBe(2);

    // La base lo frena aunque se saltee la verificación.
    $vigente = Visacion::latest('id')->first();
    expect(fn () => DB::transaction(fn () => DB::table('visaciones')->insert([
        'cobertura_paciente_id' => $vigente->cobertura_paciente_id, 'responsable_pago_id' => $vigente->responsable_pago_id, 'turno_id' => $turno->id,
        'numero' => 'V-1001', 'fecha' => '2026-10-06', 'estado_id' => Estado::idDe(Estado::VIGENTE), 'usuario_id' => $this->recepcion->id,
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(\Illuminate\Database\QueryException::class);
    // Otra aseguradora puede usar el mismo número.
    expect(DB::table('visaciones')->where('numero', 'V-1001')->whereNull('anulada_en')->count())->toBe(1);
    // Validación: sin líneas, con un porcentaje inválido o sin cobertura.
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::PORCENTAJE, '120']], 'V-3')->assertSessionHas('error', Porcentaje::MENSAJE);
});

test('cobro solo de la parte de la paciente; la de parte cero no se ofrece; la reversión devuelve lo pagado', function () {
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::PORCENTAJE, '30']]);
    $eco = conveniosVisacionesAtender($turno);
    $otroTurno = hcTurno(['procedimiento_id' => $this->eco->id, 'hora_inicio' => '10:00', 'hora_fin' => '10:30']);
    conveniosVisacionesVisacion($otroTurno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]], 'V-2');
    $cubierta = conveniosVisacionesAtender($otroTurno);
    conveniosVisacionesAbrirCaja();

    $this->actingAs($this->cajero)->get(route('admin.cobros.create', ['paciente' => $this->paciente->id]))->assertOk()
        ->assertSee('₲ 75.000')->assertSee(Prestacion::A_CARGO_DE_ASEGURADORA);
    // Con el total: rechazado (debe ser la parte de la paciente).
    conveniosVisacionesCobrar([$eco], 250000)->assertSessionHas('error');
    conveniosVisacionesCobrar([$cubierta], 1)->assertSessionHas('error', Cobrar::A_CARGO_DE_ASEGURADORA);
    conveniosVisacionesCobrar([$eco], 75000)->assertSessionHas('status', 'Cobro registrado: ₲ 75.000.');

    $cobro = Cobro::sole();
    expect((int) $cobro->total)->toBe(75000)->and((int) CobroPrestacion::sole()->monto)->toBe(75000)
        ->and($eco->fresh()->estadoCobroTexto())->toBe(Prestacion::COBRADA);

    RevertirCobro::ejecutar($this->cajero, $cobro, 'Cobro duplicado por error');
    expect(MovimientoCaja::where('tipo', MovimientoCaja::REVERSION)->sum('monto'))->toEqual(-75000)
        ->and($eco->fresh()->estadoCobroTexto())->toBe(Prestacion::PENDIENTE_DE_COBRO);
});

test('aplicar visación tarde: recalcula el snapshot y el reparto, con motivo y en Auditoría; con cobro vigente se bloquea', function () {
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    $prestacion = conveniosVisacionesAtender($turno);
    expect($prestacion->pagador)->toBe(Prestacion::PACIENTE)->and(conveniosVisacionesReparto($prestacion))->toBe(['CLINICA' => 180000, 'PROFESIONAL' => 120000]);

    // Cobrada como particular: primero hay que revertir el cobro.
    conveniosVisacionesAbrirCaja();
    conveniosVisacionesCobrar([$prestacion], 300000)->assertSessionHas('status');
    conveniosVisacionesVisacion($prestacion->consulta, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]], 'V-TARDE')->assertSessionHasNoErrors();
    $visacion = Visacion::sole();
    $this->actingAs($this->recepcion)->from(route('admin.coberturas.show', $this->paciente))
        ->post(route('admin.visaciones.aplicar', $prestacion), ['visacion_id' => $visacion->id, 'motivo_aplicacion' => 'Visación llegó tarde'])
        ->assertSessionHas('error', AplicarVisacion::COBRADA);
    expect($prestacion->fresh()->pagador)->toBe(Prestacion::PACIENTE);

    RevertirCobro::ejecutar($this->cajero, Cobro::sole(), 'Corresponde a la aseguradora');
    $this->actingAs($this->recepcion)->from(route('admin.coberturas.show', $this->paciente))
        ->post(route('admin.visaciones.aplicar', $prestacion), ['visacion_id' => $visacion->id, 'motivo_aplicacion' => 'Visación llegó tarde'])
        ->assertSessionHasNoErrors()->assertSessionHas('status');

    $aplicada = $prestacion->fresh();
    expect($aplicada->pagador)->toBe(Prestacion::ASEGURADORA)->and((int) $aplicada->monto)->toBe(250000)->and((int) $aplicada->monto_aseguradora)->toBe(250000)
        ->and($aplicada->motivo_aplicacion)->toBe('Visación llegó tarde')->and($aplicada->visacion_aplicada_por)->toBe($this->recepcion->id)
        // El reparto anterior quedó anulado (con sus líneas) y hay otro sobre el total nuevo.
        ->and(RepartoPrestacion::where('prestacion_id', $prestacion->id)->count())->toBe(2)
        ->and(RepartoPrestacion::where('prestacion_id', $prestacion->id)->whereNotNull('anulado_en')->sole()->lineas)->toHaveCount(2)
        ->and(conveniosVisacionesReparto($aplicada))->toBe(['CLINICA' => 150000, 'PROFESIONAL' => 100000]);

    $evento = LogAuditoria::where('tabla_afectada', 'prestaciones')->where('registro_afectado_id', (string) $prestacion->id)->where('accion', AccionAuditoria::EDITAR->value)
        ->where('detalle', 'like', 'Aplicar visación N.º V-TARDE%')->sole();
    expect($evento->valor_anterior['pagador'])->toBe(Prestacion::PACIENTE)->and($evento->valor_nuevo['pagador'])->toBe(Prestacion::ASEGURADORA)
        ->and($evento->detalle)->toContain('Motivo: Visación llegó tarde');

    // Una prestación anterior a los convenios no se recalcula.
    DB::table('hitos_sistema')->where('codigo', CalcularCobertura::HITO)->update(['fecha_hora' => '2026-10-07 00:00:00']);
    expect(fn () => AplicarVisacion::ejecutar($this->recepcion, $aplicada->fresh(), null, 'Volver a particular'))->toThrow(AccionRechazada::class, 'anterior a los convenios');
});

test('firma recibida: se marca con fecha y usuario y se desmarca con motivo; solo en las de aseguradora', function () {
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]]);
    $prestacion = conveniosVisacionesAtender($turno);
    $particular = conveniosVisacionesAtender(null);

    $this->actingAs($this->recepcion)->post(route('admin.visaciones.firma', $prestacion))->assertSessionHas('status', 'Firma marcada como recibida.');
    expect($prestacion->fresh()->firma_recibida_por)->toBe($this->recepcion->id)->and($prestacion->fresh()->firma_recibida_en)->not->toBeNull();

    $this->post(route('admin.visaciones.firma.desmarcar', $prestacion), ['motivo_firma' => 'x'])->assertSessionHasErrors('motivo_firma');
    $this->post(route('admin.visaciones.firma.desmarcar', $prestacion), ['motivo_firma' => 'Firmó otra persona'])->assertSessionHas('status', 'Firma desmarcada.');
    expect($prestacion->fresh()->firma_recibida_en)->toBeNull()
        ->and(LogAuditoria::where('tabla_afectada', 'prestaciones')->where('detalle', 'Firma desmarcada. Motivo: Firmó otra persona')->exists())->toBeTrue();

    $this->post(route('admin.visaciones.firma', $particular))->assertSessionHas('error');
    // Caja no la marca.
    $this->actingAs($this->cajero)->post(route('admin.visaciones.firma', $prestacion))->assertForbidden();
});

test('ticket: con todas las prestaciones de aseguradora de la consulta, sin montos ni nada clínico; con permiso y en Auditoría', function () {
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    AltaTarifa::ejecutar($this->gerente, $this->rx->id, null, 80000, Carbon::parse('2026-10-01'));
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::PORCENTAJE, '10'], [$this->rx, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]]);
    $base = conveniosVisacionesAtender($turno, [$this->rx]);
    $consulta = $base->consulta;

    $html = TicketVisacion::html($consulta, TicketVisacion::prestaciones($consulta));
    expect($html)->toContain('Carmen')->toContain('Seguros de Prueba')->toContain('Plan Oro')->toContain('AS-0001')->toContain('V-1001')
        ->toContain('Ecografía de prueba')->toContain('Radiografía de prueba')->toContain('Firma de la paciente')->toContain('Benítez')
        ->not->toContain('₲')->not->toContain('Rinofaringitis')->not->toContain('J06.9')->not->toContain('Informe inventado');

    foreach ([$this->recepcion, $this->facturacion, $this->gerente] as $usuario) {
        $respuesta = $this->actingAs($usuario)->get(route('admin.visaciones.ticket', $consulta))->assertOk();
        expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf')->and($respuesta->headers->get('Cache-Control'))->toContain('no-store');
    }
    expect(LogAuditoria::where('accion', AccionAuditoria::EXPORTAR->value)->where('tabla_afectada', 'visaciones')->count())->toBe(3);

    foreach ([$this->cajero, $this->medico] as $usuario) {
        $this->actingAs($usuario)->get(route('admin.visaciones.ticket', $consulta))->assertForbidden();
    }
    // Una consulta sin prestaciones de aseguradora no tiene ticket.
    $particular = conveniosVisacionesAtender(null);
    $this->actingAs($this->recepcion)->from(route('admin.coberturas.show', $this->paciente))->get(route('admin.visaciones.ticket', $particular->consulta_id))
        ->assertSessionHas('error', TicketVisacion::SIN_PRESTACIONES);
});

test('permisos: Facturación administra convenios; Recepción carga cobertura y visaciones; Médico y Caja no ven visaciones', function () {
    $this->actingAs($this->facturacion)->get(route('admin.convenios.index'))->assertOk()->assertSee('Convenio de prueba');
    $this->get(route('admin.convenios.tarifas'))->assertOk()->assertSee('₲ 250.000');
    $this->post(route('admin.convenios.tarifas.store'), ['convenio_id' => $this->convenio->id, 'procedimiento_id' => $this->eco->id, 'monto' => '260.000', 'vigente_desde' => '06/10/2026'])
        ->assertSessionHasNoErrors();
    expect(\App\Models\TarifaConvenio::where('procedimiento_id', $this->eco->id)->count())->toBe(2);
    $this->get(route('admin.convenios.show', $this->convenio))->assertOk()->assertSee('Plan Oro')->assertSee('Pasar a histórico');
    $this->get(route('admin.convenios.tarifas.create', ['convenio' => $this->convenio->id]))->assertOk();
    $this->get(route('admin.convenios.tarifas.show', \App\Models\TarifaConvenio::latest('id')->first()))->assertOk()->assertSee('₲ 260.000')->assertSee('Dar de baja');
    $this->patch(route('admin.convenios.planes.estado', $this->plan), ['accion' => 'desactivar'])->assertSessionHas('status');
    expect($this->plan->fresh()->estaActivo())->toBeFalse();
    $this->actingAs($this->gerente)->get(route('admin.convenios.create'))->assertOk();

    foreach ([$this->recepcion, $this->cajero, $this->medico] as $usuario) {
        $this->actingAs($usuario)->get(route('admin.convenios.index'))->assertForbidden();
    }
    $this->actingAs($this->recepcion)->get(route('admin.coberturas.show', $this->paciente))->assertOk()->assertSee('Cobertura de la paciente (todavía no tiene)');
    foreach ([$this->cajero, $this->medico] as $usuario) {
        $this->actingAs($usuario)->get(route('admin.coberturas.show', $this->paciente))->assertForbidden();
        $this->actingAs($usuario)->post(route('admin.visaciones.store', $this->paciente), [])->assertForbidden();
    }
    // Gerencia ve, pero no carga visaciones.
    $this->actingAs($this->gerente)->post(route('admin.visaciones.store', $this->paciente), [])->assertForbidden();
    // El menú: Convenios en Económico solo para quien lo administra.
    $this->actingAs($this->facturacion)->get(route('dashboard'))->assertSee(route('admin.convenios.index'));
    $this->actingAs($this->recepcion)->get(route('dashboard'))->assertDontSee(route('admin.convenios.index'));
});

test('auditoría: altas y cambios quedan registrados; los montos se ocultan a quien no tiene el módulo', function () {
    $this->actingAs($this->facturacion)->post(route('admin.convenios.store'), [
        'responsable_pago_id' => conveniosVisacionesResponsable('Otra Aseguradora')->id, 'nombre' => 'Convenio auditado', 'vigente_desde' => '01/10/2026',
        'vigente_hasta' => '', 'planes' => "Plan Único
Plan Familiar",
    ])->assertSessionHasNoErrors();
    $otro = Convenio::where('nombre', 'Convenio auditado')->sole();
    expect($otro->planes()->pluck('nombre')->all())->toBe(['Plan Familiar', 'Plan Único']);
    $this->post(route('admin.convenios.tarifas.store'), ['convenio_id' => $otro->id, 'procedimiento_id' => $this->eco->id, 'monto' => '200000', 'vigente_desde' => '01/10/2026'])
        ->assertSessionHasNoErrors();
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]]);

    foreach (['convenios', 'planes_convenio', 'tarifas_convenio', 'coberturas_paciente', 'visaciones', 'lineas_visacion'] as $tabla) {
        expect(LogAuditoria::where('tabla_afectada', $tabla)->where('accion', AccionAuditoria::CREAR->value)->exists())->toBeTrue("Falta el CREAR de {$tabla}");
    }
    expect(Auditoria::permisoDeContenido('tarifas_convenio'))->toBe('CONVENIOS')->and(Auditoria::permisoDeContenido('lineas_visacion'))->toBe('VISACIONES')
        ->and(Auditoria::permisoDeContenido('coberturas_paciente'))->toBe('COBERTURAS')
        // El Médico no tiene ninguno de los tres.
        ->and($this->medico->tienePermiso('VISACIONES', 'VER'))->toBeFalse()->and($this->medico->tienePermiso('CONVENIOS', 'VER'))->toBeFalse();
    $this->actingAs($this->medico)->get(route('admin.prestaciones.index'))->assertForbidden();
});

/** Lo que Recepción manda desde la página con el turno elegido: la visación, y la cobertura si la paciente no la tiene. */
function conveniosVisacionesEnvioUnico(Turno $turno, array $extra = [], bool $conCobertura = true)
{
    $cobertura = $conCobertura ? [
        'con_cobertura' => '1', 'plan_convenio_id' => test()->plan->id, 'numero_asegurado' => 'AS-0001', 'tipo_afiliado' => 'TITULAR',
        'vigente_desde' => '01/10/2026', 'vigente_hasta' => '',
    ] : [];

    return test()->actingAs(test()->recepcion)->from(route('admin.coberturas.show', test()->paciente))->post(route('admin.visaciones.store', test()->paciente), [
        'destino' => 'turno:'.$turno->id, 'numero' => 'V-2001', 'fecha' => '06/10/2026', 'volver' => '/admin/agenda-general?fecha=06%2F10%2F2026',
        'lineas' => [['procedimiento_id' => test()->eco->id, 'resultado' => LineaVisacion::APROBADO, 'copago' => LineaVisacion::NINGUNO, 'valor' => '']],
        ...$cobertura, ...$extra,
    ]);
}

test('el botón Visación y el estado del seguro aparecen en la Agenda general y en Turnos solo con VER sobre VISACIONES', function () {
    $turno = conveniosVisacionesTurnoEco();
    $enlace = route('admin.coberturas.show', ['paciente' => $this->paciente->id, 'turno' => $turno->id]);
    $pantallas = [route('admin.agenda-general.index', ['fecha' => '06/10/2026']), route('admin.turnos.index')];

    // Sin cobertura: solo el botón, sin estado.
    foreach ($pantallas as $url) {
        $this->actingAs($this->recepcion)->get($url)->assertOk()->assertSee('data-visacion-turno="'.$turno->id.'"', false)->assertSee('Visación')
            ->assertSee(e($enlace), false)->assertDontSee('Seguro:')->assertDontSee('Falta visación');
    }

    // Con cobertura vigente y sin visación: "Seguro: aseguradora" y "Falta visación"; con la visación: "Visación cargada". Sin montos.
    conveniosVisacionesCobertura();
    foreach ($pantallas as $url) {
        $this->actingAs($this->recepcion)->get($url)->assertSee('Seguro: Seguros de Prueba, S.A.')->assertSee('Falta visación')->assertDontSee('Visación cargada');
    }
    conveniosVisacionesVisacion($turno, [[$this->eco, LineaVisacion::APROBADO, LineaVisacion::NINGUNO]]);
    foreach ($pantallas as $url) {
        $this->actingAs($this->recepcion)->get($url)->assertSee('Visación cargada')->assertDontSee('Falta visación')->assertDontSee('₲');
    }

    // Una visación anulada vuelve a mostrar "Falta visación".
    Visacion::sole()->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO)])->saveQuietly();
    $this->actingAs($this->recepcion)->get(route('admin.turnos.index'))->assertSee('Falta visación');

    // Sin VER sobre VISACIONES (el Médico ve la agenda): nada de esto.
    foreach ($pantallas as $url) {
        $this->actingAs($this->medico)->get($url)->assertOk()->assertDontSee('data-visacion-turno', false)->assertDontSee('Seguro:')->assertDontSee('Falta visación');
    }

    // Un turno cancelado no lleva el botón.
    $turno->forceFill(['estado_id' => Estado::idDe(Estado::CANCELADO)])->save();
    $this->actingAs($this->recepcion)->get($pantallas[0])->assertDontSee('data-visacion-turno="'.$turno->id.'"', false);
});

test('la página abre con el turno elegido y el procedimiento del turno precargado como primera línea', function () {
    $turno = conveniosVisacionesTurnoEco();
    $volver = '/admin/turnos?q=Duarte';
    $html = $this->actingAs($this->recepcion)->get(route('admin.coberturas.show', ['paciente' => $this->paciente->id, 'turno' => $turno->id, 'volver' => $volver]))
        ->assertOk()->assertSee('name="volver" value="'.e($volver).'"', false)->getContent();

    expect($html)->toMatch('/<option value="turno:'.$turno->id.'"[^>]*selected/')
        ->and($html)->toMatch('/name="lineas\[0\]\[procedimiento_id\]".*?<option value="'.$this->eco->id.'"[^>]*selected/s');
    // El turno de otra paciente no se precarga.
    $otro = \App\Models\Paciente::create(['persona_id' => Persona::factory()->create()->id, 'nro_ficha' => 'FP-0000002']);
    $this->get(route('admin.coberturas.show', ['paciente' => $otro->id, 'turno' => $turno->id]))->assertOk()->assertDontSee('turno:'.$turno->id);
});

test('sin cobertura: cobertura y visación se guardan en un solo envío y se vuelve a la pantalla de origen con el aviso', function () {
    $turno = conveniosVisacionesTurnoEco();
    $this->actingAs($this->recepcion)->get(route('admin.coberturas.show', ['paciente' => $this->paciente->id, 'turno' => $turno->id]))
        ->assertOk()->assertSee('Cobertura de la paciente (todavía no tiene)')->assertSee('name="con_cobertura"', false);

    $respuesta = conveniosVisacionesEnvioUnico($turno)->assertSessionHasNoErrors()->assertRedirect('/admin/agenda-general?fecha=06%2F10%2F2026');
    $respuesta->assertSessionHas('status', 'Visación N.º V-2001 cargada junto con la cobertura.');
    $visacion = Visacion::sole();
    expect(CoberturaPaciente::where('paciente_id', $this->paciente->id)->whereNull('cerrada_en')->count())->toBe(1)
        ->and($visacion->turno_id)->toBe($turno->id)->and($visacion->lineas()->count())->toBe(1);
});

test('si falla la visación no queda la cobertura, y si ya la tiene se guarda solo la visación', function () {
    $turno = conveniosVisacionesTurnoEco();
    // El turno elegido no es de la paciente: la visación falla después de cargar la cobertura, y todo se deshace.
    conveniosVisacionesEnvioUnico($turno, ['destino' => 'turno:999999'])->assertSessionHas('error');
    expect(CoberturaPaciente::count())->toBe(0)->and(Visacion::count())->toBe(0);
    // Los datos de la cobertura inválidos tampoco dejan la visación.
    conveniosVisacionesEnvioUnico($turno, ['numero_asegurado' => ''])->assertSessionHasErrors('numero_asegurado');
    expect(CoberturaPaciente::count())->toBe(0)->and(Visacion::count())->toBe(0);

    conveniosVisacionesCobertura();
    conveniosVisacionesEnvioUnico($turno, conCobertura: false)->assertSessionHasNoErrors()->assertRedirect('/admin/agenda-general?fecha=06%2F10%2F2026')
        ->assertSessionHas('status', 'Visación N.º V-2001 cargada.');
    expect(CoberturaPaciente::count())->toBe(1)->and(Visacion::count())->toBe(1);
});

test('con cobertura vigente la página la muestra resumida y la vuelta solo acepta rutas del propio sitio', function () {
    conveniosVisacionesCobertura();
    $turno = conveniosVisacionesTurnoEco();
    $this->actingAs($this->recepcion)->get(route('admin.coberturas.show', ['paciente' => $this->paciente->id, 'turno' => $turno->id]))
        ->assertOk()->assertSee('Seguro:')->assertSee('AS-0001')->assertSee('Cambiar la cobertura')->assertDontSee('Cobertura de la paciente (todavía no tiene)');

    conveniosVisacionesEnvioUnico($turno, ['volver' => '//sitio-ajeno.example/x'], conCobertura: false)
        ->assertRedirect(route('admin.coberturas.show', $this->paciente));
    conveniosVisacionesEnvioUnico($turno, ['numero' => 'V-2002', 'volver' => 'https://sitio-ajeno.example/x'], conCobertura: false)
        ->assertRedirect(route('admin.coberturas.show', $this->paciente));
});
