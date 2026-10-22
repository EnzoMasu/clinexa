<?php

/*
 * Reversión de cobros (docs/cobros-caja.md): directa en la caja abierta propia, con solicitud y aprobación de Gerencia en los demás
 * casos (caja cerrada o de otro cajero), rechazo, nadie revisa la propia, una sola solicitud pendiente, el cobro mixto devuelto por
 * cada medio en la caja abierta de quien devuelve, el cierre que lo descuenta, las cajas cerradas intactas, la prestación que vuelve
 * a estar sin cobrar (se cobra y se anula después), la doble reversión, los permisos y la auditoría. Datos y montos inventados.
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Caja;
use App\Models\CierreCaja;
use App\Models\Cobro;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\SolicitudReversionCobro;
use App\Models\User;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\RevertirCobro;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\AnularPrestacion;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    hcEscenario(); // "hoy": martes 06/10/2026, 09:00 en Paraguay
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $this->consultaProc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->eco = Procedimiento::create(['codigo' => 'ECO-PRUEBA', 'nombre' => 'Ecografía de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);
    $this->cajero = reversionesCobroUsuario(PerfilesPredefinidos::CAJA, ['apellidos' => 'Cáceres', 'nombres' => 'Lidia']);
    $this->otroCajero = reversionesCobroUsuario(PerfilesPredefinidos::CAJA, ['apellidos' => 'Ortiz', 'nombres' => 'Marta']);
    $this->gerente = reversionesCobroUsuario(PerfilesPredefinidos::GERENCIA, ['apellidos' => 'Gómez', 'nombres' => 'Ana']);
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $this->consultaProc->id, null, 100000, Carbon::parse('2026-10-01'));
    AltaTarifa::ejecutar($this->gerente, $this->eco->id, null, 250000, Carbon::parse('2026-10-01'));
    [$this->efectivo, $this->transferencia, $this->tarjeta] = [MedioPago::where('codigo', MedioPago::EFECTIVO)->sole(),
        MedioPago::where('codigo', MedioPago::TRANSFERENCIA)->sole(), MedioPago::where('codigo', MedioPago::TARJETA)->sole()];
});

afterEach(fn () => Carbon::setTestNow());

function reversionesCobroUsuario(string $codigo, array $persona = []): User
{
    $factory = User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()]);

    return ($persona ? $factory->conPersona($persona) : $factory)->create();
}

/** La prestación de una consulta finalizada de la paciente (de consulta, ₲ 100.000; o de ecografía, ₲ 250.000). */
function reversionesCobroPrestacion(bool $ecografia = false): Prestacion
{
    test()->actingAs(test()->medico);
    if ($ecografia) {
        hcGuardarNueva([], hcTurno(['procedimiento_id' => test()->eco->id]))->assertSessionHasNoErrors();
    } else {
        hcConsulta();
    }

    return Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
}

function reversionesCobroAbrir(User $cajero, string $monto = '50.000'): Caja
{
    test()->actingAs($cajero)->post(route('admin.cajas.abrir'), ['monto_inicial' => $monto])->assertSessionHas('status');

    return Caja::where('usuario_id', $cajero->id)->latest('id')->firstOrFail();
}

/** Cobra por la pantalla: prestaciones y líneas [medio, monto]. Devuelve el cobro. */
function reversionesCobroCobrar(User $cajero, array $prestaciones, array $lineas): Cobro
{
    test()->actingAs($cajero)->from(route('admin.cobros.create'))->post(route('admin.cobros.store'), [
        'paciente_id' => test()->paciente->id,
        'prestaciones' => array_map(fn (Prestacion $prestacion) => $prestacion->id, $prestaciones),
        'lineas' => array_map(fn (array $linea) => ['medio_pago_id' => $linea[0]->id, 'monto' => $linea[1]], $lineas),
    ])->assertSessionHas('status');

    return Cobro::latest('id')->firstOrFail();
}

/** Cierra la caja con lo esperado (sin diferencia). */
function reversionesCobroCerrar(User $cajero, Caja $caja): void
{
    $contado = MedioPago::pluck('id')->mapWithKeys(fn ($id) => [$id => (string) ($caja->esperadoPorMedio()[$id] ?? 0)])->all();
    test()->actingAs($cajero)->post(route('admin.cajas.cerrar', $caja), ['contado' => $contado])->assertSessionHas('status');
}

function reversionesCobroRevertir(User $usuario, Cobro $cobro, ?string $motivo = 'Cobro cargado por error, motivo inventado.')
{
    return test()->actingAs($usuario)->from(route('admin.cobros.show', $cobro))->post(route('admin.cobros.revertir', $cobro), ['motivo' => $motivo]);
}

function reversionesCobroSolicitar(User $usuario, Cobro $cobro, ?string $motivo = 'La paciente pagó dos veces, motivo inventado.')
{
    return test()->actingAs($usuario)->from(route('admin.cobros.show', $cobro))->post(route('admin.cobros.solicitar-reversion', $cobro), ['motivo' => $motivo]);
}

/** Los movimientos de una caja: [tipo, medio, monto]. */
function reversionesCobroMovimientos(Caja $caja): array
{
    return MovimientoCaja::where('caja_id', $caja->id)->orderBy('id')->get()->map(fn ($m) => [$m->tipo, $m->medio_pago_id, (int) $m->monto])->all();
}

test('reversión directa en la caja propia abierta: con motivo, el cobro queda Revertido, la prestación Pendiente y el egreso en la caja', function () {
    $prestacion = reversionesCobroPrestacion();
    $caja = reversionesCobroAbrir($this->cajero);
    $cobro = reversionesCobroCobrar($this->cajero, [$prestacion], [[$this->efectivo, '100.000']]);

    $this->get(route('admin.cobros.show', $cobro))->assertOk()->assertSee('Revertir el cobro')->assertDontSee('Solicitar la reversión');
    reversionesCobroRevertir($this->cajero, $cobro, '')->assertSessionHasErrors('motivo');
    reversionesCobroRevertir($this->cajero, $cobro, 'abc')->assertSessionHasErrors('motivo');
    expect($cobro->fresh()->revertido())->toBeFalse();

    reversionesCobroRevertir($this->cajero, $cobro)->assertSessionHas('status', 'Cobro revertido: se devolvieron ₲ 100.000 desde su caja.');

    $cobro->refresh();
    expect($cobro->revertido())->toBeTrue()->and($cobro->motivo_reversion)->toBe('Cobro cargado por error, motivo inventado.')
        ->and($cobro->revertido_por)->toBe($this->cajero->id)->and($cobro->caja_reversion_id)->toBe($caja->id)->and($cobro->revertido_en)->not->toBeNull()
        ->and($prestacion->fresh()->estadoCobroTexto())->toBe(Prestacion::PENDIENTE_DE_COBRO)
        ->and(reversionesCobroMovimientos($caja))->toBe([
            [MovimientoCaja::APERTURA, $this->efectivo->id, 50000], [MovimientoCaja::COBRO, $this->efectivo->id, 100000], [MovimientoCaja::REVERSION, $this->efectivo->id, -100000],
        ])
        ->and($caja->esperadoPorMedio())->toBe([$this->efectivo->id => 50000])
        // Nada se borra: el cobro, sus prestaciones y líneas siguen ahí.
        ->and($cobro->prestaciones()->count())->toBe(1)->and($cobro->lineas()->count())->toBe(1);

    $this->get(route('admin.cobros.show', $cobro))->assertOk()->assertSee('Revertido el 06/10/2026')->assertSee('Cobro cargado por error, motivo inventado.')
        ->assertDontSee('Revertir el cobro')->assertDontSee('Solicitar la reversión');
    $this->get(route('admin.cobros.index', ['estado' => Estado::REVERTIDO]))->assertOk()->assertSee('Revertido')->assertSee('Duarte');
    $this->get(route('admin.cobros.index', ['estado' => Estado::VIGENTE]))->assertOk()->assertDontSee('Duarte');
    $this->get(route('admin.cajas.show', $caja))->assertOk()->assertSee('Devoluciones de cobros revertidos (1)')->assertSee('₲ -100.000');
});

test('un cobro se revierte una sola vez: la segunda reversión y la solicitud sobre un revertido se rechazan', function () {
    $caja = reversionesCobroAbrir($this->cajero);
    $cobro = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    reversionesCobroRevertir($this->cajero, $cobro)->assertSessionHas('status');

    reversionesCobroRevertir($this->cajero, $cobro)->assertSessionHas('error', RevertirCobro::YA_REVERTIDO);
    reversionesCobroSolicitar($this->otroCajero, $cobro)->assertSessionHas('error', RevertirCobro::YA_REVERTIDO);
    expect(fn () => RevertirCobro::ejecutar($this->cajero, $cobro, 'Otro motivo inventado.'))->toThrow(\App\Exceptions\AccionRechazada::class, RevertirCobro::YA_REVERTIDO)
        ->and(MovimientoCaja::where('tipo', MovimientoCaja::REVERSION)->count())->toBe(1)
        ->and(SolicitudReversionCobro::count())->toBe(0)
        ->and($caja->esperadoPorMedio())->toBe([$this->efectivo->id => 50000]);
});

test('no se revierte directo un cobro de una caja cerrada ni de otro cajero: se solicita', function () {
    $caja = reversionesCobroAbrir($this->cajero);
    $cobro = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);

    // De otro cajero (aunque la caja siga abierta).
    reversionesCobroAbrir($this->otroCajero, '0');
    $this->get(route('admin.cobros.show', $cobro))->assertOk()->assertSee('Solicitar la reversión')->assertDontSee('Revertir el cobro');
    reversionesCobroRevertir($this->otroCajero, $cobro)->assertSessionHas('error', RevertirCobro::NECESITA_APROBACION);

    // De la caja propia, ya cerrada.
    reversionesCobroCerrar($this->cajero, $caja);
    $this->actingAs($this->cajero)->get(route('admin.cobros.show', $cobro))->assertOk()->assertSee('Solicitar la reversión')->assertDontSee('Revertir el cobro');
    reversionesCobroRevertir($this->cajero, $cobro)->assertSessionHas('error', RevertirCobro::NECESITA_APROBACION);
    expect($cobro->fresh()->revertido())->toBeFalse()->and(MovimientoCaja::where('tipo', MovimientoCaja::REVERSION)->exists())->toBeFalse();

    // Y lo directo no se pide como solicitud.
    $propio = reversionesCobroCobrar($this->otroCajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    reversionesCobroSolicitar($this->otroCajero, $propio)->assertSessionHas('error', RevertirCobro::ES_DIRECTA);
});

test('solicitud y aprobación de un cobro mixto: se devuelve cada línea por su medio en la caja abierta del solicitante y el cierre lo descuenta', function () {
    $eco = reversionesCobroPrestacion(ecografia: true);
    $vieja = reversionesCobroAbrir($this->cajero, '0');
    $cobro = reversionesCobroCobrar($this->cajero, [$eco], [[$this->efectivo, '100.000'], [$this->transferencia, '150.000']]);
    reversionesCobroCerrar($this->cajero, $vieja);
    $this->actingAs($this->gerente)->post(route('admin.cajas.aprobar', $vieja))->assertSessionHas('status');
    $antes = [reversionesCobroMovimientos($vieja), $vieja->fresh()->estado_id, CierreCaja::where('caja_id', $vieja->id)->get()->toArray()];

    reversionesCobroSolicitar($this->cajero, $cobro, '')->assertSessionHasErrors('motivo');
    reversionesCobroSolicitar($this->cajero, $cobro)->assertSessionHas('status', 'Solicitud de reversión enviada: queda pendiente de aprobación.');
    $solicitud = SolicitudReversionCobro::sole();
    expect($solicitud->tieneEstado(Estado::PENDIENTE))->toBeTrue()->and($solicitud->usuario_id)->toBe($this->cajero->id)->and($cobro->fresh()->revertido())->toBeFalse();
    $this->get(route('admin.cobros.show', $cobro))->assertOk()->assertSee('Reversión pendiente de aprobación')->assertDontSee('Aprobar la reversión');

    // Gerencia la ve en la pestaña. Sin la caja abierta de hoy del solicitante, no se aprueba.
    $this->actingAs($this->gerente)->get(route('admin.cobros.index'))->assertOk()->assertSee('Reversiones pendientes (1)');
    $this->get(route('admin.cobros.reversiones'))->assertOk()->assertSee('La paciente pagó dos veces, motivo inventado.')->assertSee('Cáceres')->assertSee('Aprobar');
    $this->get(route('admin.cobros.show', $cobro))->assertOk()->assertSee('Aprobar la reversión')->assertSee('no tiene abierta la caja de hoy');
    $this->post(route('admin.cobros.reversiones.aprobar', $solicitud))->assertSessionHas('error', RevertirCobro::sinCajaDelSolicitante('Cáceres, Lidia'));

    // Con la caja abierta pero sin el efectivo que hay que devolver, tampoco (la transferencia no necesita fondos).
    $nueva = reversionesCobroAbrir($this->cajero, '0');
    $this->actingAs($this->gerente)->post(route('admin.cobros.reversiones.aprobar', $solicitud))
        ->assertSessionHas('error', 'No alcanza el efectivo de la caja para devolver ₲ 100.000 (hay ₲ 0).');
    expect($cobro->fresh()->revertido())->toBeFalse()->and($solicitud->fresh()->pendiente())->toBeTrue()
        ->and(MovimientoCaja::where('tipo', MovimientoCaja::REVERSION)->exists())->toBeFalse();

    $otro = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    $this->actingAs($this->gerente)->from(route('admin.cobros.reversiones'))->post(route('admin.cobros.reversiones.aprobar', $solicitud), ['nota' => 'Verificado.'])
        ->assertRedirect(route('admin.cobros.reversiones'))->assertSessionHas('status', "Reversión aprobada: el cobro N.º {$cobro->id} quedó revertido.");

    $cobro->refresh();
    expect($cobro->revertido())->toBeTrue()->and($cobro->revertido_por)->toBe($this->cajero->id)->and($cobro->caja_reversion_id)->toBe($nueva->id)
        ->and($cobro->motivo_reversion)->toBe('La paciente pagó dos veces, motivo inventado.')
        ->and($solicitud->fresh()->tieneEstado(Estado::APROBADO))->toBeTrue()->and($solicitud->fresh()->revisado_por)->toBe($this->gerente->id)
        ->and($eco->fresh()->estadoCobroTexto())->toBe(Prestacion::PENDIENTE_DE_COBRO)
        ->and(reversionesCobroMovimientos($nueva))->toBe([
            [MovimientoCaja::COBRO, $this->efectivo->id, 100000],
            [MovimientoCaja::REVERSION, $this->efectivo->id, -100000], [MovimientoCaja::REVERSION, $this->transferencia->id, -150000],
        ])
        ->and($nueva->esperadoPorMedio())->toBe([$this->efectivo->id => 0, $this->transferencia->id => -150000])
        // La caja cerrada y aprobada no se tocó.
        ->and([reversionesCobroMovimientos($vieja), $vieja->fresh()->estado_id, CierreCaja::where('caja_id', $vieja->id)->get()->toArray()])->toBe($antes)
        ->and($otro->fresh()->revertido())->toBeFalse();

    // El cierre de la caja nueva espera lo cobrado menos lo devuelto (la transferencia, en negativo, declarada igual: sin diferencia).
    reversionesCobroCerrar($this->cajero, $nueva);
    $cierre = CierreCaja::where('caja_id', $nueva->id)->sole();
    expect($cierre->medios()->pluck('esperado', 'medio_pago_id')->map(fn ($valor) => (int) $valor)->only([$this->efectivo->id, $this->transferencia->id])->all())
        ->toBe([$this->efectivo->id => 0, $this->transferencia->id => -150000])
        ->and($cierre->medios()->where('diferencia', '!=', 0)->exists())->toBeFalse()->and($cierre->motivo_diferencia)->toBeNull();
    $this->actingAs($this->gerente)->get(route('admin.cobros.reversiones'))->assertOk()->assertSee('No hay solicitudes de reversión pendientes.');
});

test('transferencia y tarjeta devueltas desde una caja recién abierta: lo esperado queda negativo y el cierre lo maneja', function () {
    $vieja = reversionesCobroAbrir($this->cajero, '0');
    $cobro = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion(ecografia: true)], [[$this->transferencia, '150.000'], [$this->tarjeta, '100.000']]);
    reversionesCobroCerrar($this->cajero, $vieja);
    reversionesCobroSolicitar($this->cajero, $cobro)->assertSessionHas('status');
    $nueva = reversionesCobroAbrir($this->cajero, '20.000');
    $this->actingAs($this->gerente)->post(route('admin.cobros.reversiones.aprobar', SolicitudReversionCobro::sole()))->assertSessionHas('status');

    expect($nueva->esperadoPorMedio())->toBe([$this->efectivo->id => 20000, $this->transferencia->id => -150000, $this->tarjeta->id => -100000]);
    $this->actingAs($this->cajero)->get(route('admin.cajas.show', $nueva))->assertOk()
        ->assertSee('Transferencia (esperado: ₲ -150.000)')->assertSee('Tarjeta (POS) (esperado: ₲ -100.000)')->assertSee('declare el neto con el signo menos');

    $declarar = fn (array $contado, ?string $motivo = null) => $this->actingAs($this->cajero)->from(route('admin.cajas.show', $nueva))->post(route('admin.cajas.cerrar', $nueva), [
        'contado' => MedioPago::pluck('id')->mapWithKeys(fn ($id) => [$id => $contado[$id] ?? '0'])->all(), 'motivo' => $motivo,
    ]);
    // El efectivo no se declara negativo; los demás medios sí.
    $declarar([$this->efectivo->id => '-1', $this->transferencia->id => '-150.000', $this->tarjeta->id => '-100.000'])
        ->assertSessionHas('error', \App\Support\Cobros\CerrarCaja::EFECTIVO_NEGATIVO);
    // Declarado en cero, la diferencia se ve y pide motivo.
    $declarar([$this->efectivo->id => '20.000'])->assertSessionHas('error', \App\Support\Cobros\CerrarCaja::FALTA_MOTIVO);
    expect($nueva->fresh()->abierta())->toBeTrue();

    $declarar([$this->efectivo->id => '20.000', $this->transferencia->id => '-150.000', $this->tarjeta->id => '-90.000'], 'Falta un comprobante de la devolución por POS.')
        ->assertSessionHas('status');
    $cierre = CierreCaja::where('caja_id', $nueva->id)->sole();
    $filas = $cierre->medios()->get()->keyBy('medio_pago_id');
    expect([(int) $filas[$this->transferencia->id]->esperado, (int) $filas[$this->transferencia->id]->contado, (int) $filas[$this->transferencia->id]->diferencia])->toBe([-150000, -150000, 0])
        ->and([(int) $filas[$this->tarjeta->id]->esperado, (int) $filas[$this->tarjeta->id]->contado, (int) $filas[$this->tarjeta->id]->diferencia])->toBe([-100000, -90000, 10000])
        ->and($filas[$this->efectivo->id]->es_efectivo)->toBeTruthy()->and($filas[$this->tarjeta->id]->es_efectivo)->toBeFalsy();

    // Gerencia ve el cierre con los negativos y lo aprueba.
    $this->actingAs($this->gerente)->get(route('admin.cajas.show', $nueva))->assertOk()->assertSee('₲ -150.000')->assertSee('₲ 10.000')
        ->assertSee('Falta un comprobante de la devolución por POS.')->assertSee('Aprobar el cierre');
    $this->post(route('admin.cajas.aprobar', $nueva))->assertSessionHas('status', 'Cierre aprobado.');
    expect($nueva->fresh()->tieneEstado(Estado::APROBADO))->toBeTrue();
});

test('el medio efectivo es el marcado con es_efectivo, no el del nombre', function () {
    $this->efectivo->forceFill(['nombre' => 'Caja chica'])->save();
    expect(MedioPago::efectivo()->is($this->efectivo))->toBeTrue()->and($this->transferencia->esEfectivo())->toBeFalse()
        ->and(MedioPago::where('es_efectivo', true)->count())->toBe(1);
    // A lo sumo uno.
    expect(fn () => DB::transaction(fn () => DB::table('medios_pago')->where('id', $this->tarjeta->id)->update(['es_efectivo' => true])))->toThrow(QueryException::class);
});

test('rechazo con nota: el cobro sigue vigente y se puede volver a solicitar', function () {
    $caja = reversionesCobroAbrir($this->cajero);
    $cobro = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    reversionesCobroCerrar($this->cajero, $caja);
    reversionesCobroSolicitar($this->cajero, $cobro)->assertSessionHas('status');
    $solicitud = SolicitudReversionCobro::sole();

    $this->actingAs($this->gerente)->from(route('admin.cobros.show', $cobro))->post(route('admin.cobros.reversiones.rechazar', $solicitud), ['nota_rechazo' => ''])
        ->assertSessionHasErrors('nota_rechazo');
    $this->post(route('admin.cobros.reversiones.rechazar', $solicitud), ['nota_rechazo' => 'No corresponde, nota inventada.'])
        ->assertSessionHas('status', 'Solicitud de reversión rechazada.');

    expect($solicitud->fresh()->tieneEstado(Estado::RECHAZADO))->toBeTrue()->and($solicitud->fresh()->nota_revision)->toBe('No corresponde, nota inventada.')
        ->and($cobro->fresh()->revertido())->toBeFalse()->and(MovimientoCaja::where('tipo', MovimientoCaja::REVERSION)->exists())->toBeFalse();
    $this->post(route('admin.cobros.reversiones.aprobar', $solicitud))->assertSessionHas('error', 'Esta solicitud ya fue revisada.');
    $this->get(route('admin.cobros.show', $cobro))->assertOk()->assertSee('Rechazada')->assertSee('No corresponde, nota inventada.');

    reversionesCobroSolicitar($this->cajero, $cobro)->assertSessionHas('status');
    expect(SolicitudReversionCobro::count())->toBe(2)->and(SolicitudReversionCobro::pendientes()->count())->toBe(1);
});

test('nadie aprueba ni rechaza su propia solicitud', function () {
    $gerenteCajero = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilesPredefinidos::GERENCIA)->sole(), PerfilAcceso::where('codigo', PerfilesPredefinidos::CAJA)->sole()])
        ->conPersona(['apellidos' => 'Benítez', 'nombres' => 'Rosa'])->create();
    $caja = reversionesCobroAbrir($gerenteCajero);
    $cobro = reversionesCobroCobrar($gerenteCajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    reversionesCobroCerrar($gerenteCajero, $caja);
    reversionesCobroAbrir($gerenteCajero, '100.000');
    reversionesCobroSolicitar($gerenteCajero, $cobro)->assertSessionHas('status');
    $solicitud = SolicitudReversionCobro::sole();

    $this->get(route('admin.cobros.show', $cobro))->assertOk()->assertDontSee('Aprobar la reversión');
    $this->get(route('admin.cobros.reversiones'))->assertOk()->assertSee('Benítez')->assertDontSee(route('admin.cobros.reversiones.aprobar', $solicitud));
    $this->post(route('admin.cobros.reversiones.aprobar', $solicitud))->assertSessionHas('error', RevertirCobro::PROPIA);
    $this->post(route('admin.cobros.reversiones.rechazar', $solicitud), ['nota_rechazo' => 'Nota inventada.'])->assertSessionHas('error', RevertirCobro::PROPIA);
    expect($solicitud->fresh()->pendiente())->toBeTrue()->and($cobro->fresh()->revertido())->toBeFalse();

    // Otra persona de Gerencia sí.
    $this->actingAs($this->gerente)->post(route('admin.cobros.reversiones.aprobar', $solicitud))->assertSessionHas('status');
    expect($cobro->fresh()->revertido())->toBeTrue();
});

test('una sola solicitud pendiente por cobro; mientras esté, el cajero tampoco lo revierte directo', function () {
    reversionesCobroAbrir($this->cajero);
    $cobro = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    reversionesCobroAbrir($this->otroCajero);

    reversionesCobroSolicitar($this->otroCajero, $cobro)->assertSessionHas('status');
    reversionesCobroSolicitar($this->otroCajero, $cobro)->assertSessionHas('error', RevertirCobro::YA_SOLICITADA);
    // El cajero del cobro (caja abierta) ya no lo revierte directo: está la solicitud.
    $this->actingAs($this->cajero)->get(route('admin.cobros.show', $cobro))->assertOk()->assertSee('Reversión pendiente de aprobación')->assertDontSee('Revertir el cobro');
    reversionesCobroRevertir($this->cajero, $cobro)->assertSessionHas('error', RevertirCobro::YA_SOLICITADA);

    // El índice único parcial frena una segunda pendiente aunque algo se saltee la verificación.
    expect(fn () => DB::transaction(fn () => DB::table('solicitudes_reversion_cobro')->insert([
        'cobro_id' => $cobro->id, 'usuario_id' => $this->cajero->id, 'solicitada_en' => now(), 'motivo' => 'Duplicada inventada.',
        'estado_id' => Estado::idDe(Estado::PENDIENTE), 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class)
        ->and(SolicitudReversionCobro::where('cobro_id', $cobro->id)->count())->toBe(1);
});

test('la prestación revertida vuelve a Pendiente, se cobra de nuevo y, revertida otra vez, se puede anular', function () {
    $prestacion = reversionesCobroPrestacion();
    $caja = reversionesCobroAbrir($this->cajero);
    $cobro = reversionesCobroCobrar($this->cajero, [$prestacion], [[$this->efectivo, '100.000']]);
    $this->actingAs($this->gerente)->post(route('admin.prestaciones.anular', $prestacion), ['motivo' => 'Motivo inventado de prueba.'])
        ->assertSessionHas('error', AnularPrestacion::COBRADA);

    reversionesCobroRevertir($this->cajero, $cobro)->assertSessionHas('status');
    expect(Cobrar::pendientes()->whereKey($prestacion->id)->exists())->toBeTrue();
    $this->get(route('admin.cobros.create', ['paciente' => $this->paciente->id]))->assertOk()->assertSee('value="'.$prestacion->id.'"', false);

    $nuevo = reversionesCobroCobrar($this->cajero, [$prestacion], [[$this->transferencia, '100.000']]);
    expect($nuevo->id)->not->toBe($cobro->id)->and($prestacion->fresh()->estadoCobroTexto())->toBe(Prestacion::COBRADA)
        ->and($prestacion->fresh()->cobroVigente->cobro_id)->toBe($nuevo->id);

    reversionesCobroRevertir($this->cajero, $nuevo)->assertSessionHas('status');
    $this->actingAs($this->gerente)->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertSee('Anular la prestación');
    $this->post(route('admin.prestaciones.anular', $prestacion), ['motivo' => 'Motivo inventado de prueba.'])->assertSessionHas('status');
    expect($prestacion->fresh()->anulada())->toBeTrue()->and($caja->esperadoPorMedio())->toBe([$this->efectivo->id => 50000, $this->transferencia->id => 0]);
});

test('permisos: Caja revierte y solicita; Gerencia aprueba; Recepción, Facturación, Médico y Enfermería no; Administrador todo', function () {
    $caja = reversionesCobroAbrir($this->cajero);
    $cobro = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    reversionesCobroCerrar($this->cajero, $caja);
    reversionesCobroSolicitar($this->cajero, $cobro)->assertSessionHas('status');
    $solicitud = SolicitudReversionCobro::sole();

    $sinPermiso = [reversionesCobroUsuario(PerfilesPredefinidos::RECEPCION), reversionesCobroUsuario(PerfilesPredefinidos::FACTURACION),
        $this->medico, reversionesCobroUsuario(PerfilesPredefinidos::ENFERMERIA)];
    foreach ($sinPermiso as $usuario) {
        $this->actingAs($usuario);
        $this->post(route('admin.cobros.revertir', $cobro), ['motivo' => 'Motivo inventado.'])->assertForbidden();
        $this->post(route('admin.cobros.solicitar-reversion', $cobro), ['motivo' => 'Motivo inventado.'])->assertForbidden();
        $this->get(route('admin.cobros.reversiones'))->assertForbidden();
        $this->post(route('admin.cobros.reversiones.aprobar', $solicitud))->assertForbidden();
        $this->post(route('admin.cobros.reversiones.rechazar', $solicitud), ['nota_rechazo' => 'Nota inventada.'])->assertForbidden();
    }
    // Facturación ve el cobro, sin los botones ni la pestaña.
    $this->actingAs($sinPermiso[1])->get(route('admin.cobros.show', $cobro))->assertOk()->assertDontSee('Solicitar la reversión')->assertDontSee('Aprobar la reversión');
    $this->get(route('admin.cobros.index'))->assertOk()->assertDontSee('Reversiones pendientes');

    // Caja no revisa; Gerencia no revierte ni solicita (no tiene caja).
    $this->actingAs($this->otroCajero)->get(route('admin.cobros.reversiones'))->assertForbidden();
    $this->post(route('admin.cobros.reversiones.aprobar', $solicitud))->assertForbidden();
    $this->get(route('admin.cobros.index'))->assertOk()->assertDontSee('Reversiones pendientes');
    $this->actingAs($this->gerente)->post(route('admin.cobros.revertir', $cobro), ['motivo' => 'Motivo inventado.'])->assertForbidden();
    $this->post(route('admin.cobros.solicitar-reversion', $cobro), ['motivo' => 'Motivo inventado.'])->assertForbidden();
    $this->get(route('admin.cobros.show', $cobro))->assertOk()->assertDontSee('Solicitar la reversión');

    // El Administrador puede revisar (no es su solicitud).
    $admin = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->sole()])->create();
    $this->actingAs($admin)->get(route('admin.cobros.reversiones'))->assertOk()->assertSee(route('admin.cobros.reversiones.aprobar', $solicitud));
    reversionesCobroAbrir($this->cajero, '100.000');
    $this->actingAs($admin)->post(route('admin.cobros.reversiones.aprobar', $solicitud))->assertSessionHas('status');
    expect($cobro->fresh()->revertido())->toBeTrue();
});

test('auditoría de la reversión, la solicitud, la aprobación y el rechazo; sin el módulo no se ven los montos ni el motivo', function () {
    $caja = reversionesCobroAbrir($this->cajero);
    $directo = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    reversionesCobroRevertir($this->cajero, $directo, 'Motivo directo inventado.')->assertSessionHas('status');
    $aprobado = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    $rechazado = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    reversionesCobroCerrar($this->cajero, $caja);
    reversionesCobroSolicitar($this->cajero, $aprobado, 'Motivo aprobado inventado.');
    reversionesCobroSolicitar($this->cajero, $rechazado, 'Motivo rechazado inventado.');
    [$paraAprobar, $paraRechazar] = SolicitudReversionCobro::orderBy('id')->get()->all();
    reversionesCobroAbrir($this->cajero, '100.000');
    $this->actingAs($this->gerente)->post(route('admin.cobros.reversiones.aprobar', $paraAprobar), ['nota' => 'Visto.'])->assertSessionHas('status');
    $this->post(route('admin.cobros.reversiones.rechazar', $paraRechazar), ['nota_rechazo' => 'No corresponde.'])->assertSessionHas('status');

    $log = fn (string $tabla, int $id, AccionAuditoria $accion) => LogAuditoria::where('tabla_afectada', $tabla)
        ->where('registro_afectado_id', (string) $id)->where('accion', $accion->value)->orderBy('id')->get();
    $revertir = $log('cobros', $directo->id, AccionAuditoria::REVERTIR)->sole();
    expect($revertir->detalle)->toBe('Reversión del cobro. Motivo: Motivo directo inventado.')->and($revertir->usuario_id)->toBe($this->cajero->id)
        ->and($log('cobro_prestaciones', $directo->prestaciones()->sole()->id, AccionAuditoria::EDITAR)->sole()->detalle)->toBe("Reversión del cobro {$directo->id}")
        ->and($log('cobros', $aprobado->id, AccionAuditoria::REVERTIR)->sole()->usuario_id)->toBe($this->gerente->id)
        ->and($log('solicitudes_reversion_cobro', $paraAprobar->id, AccionAuditoria::CREAR)->sole()->detalle)->toBe("Solicitud de reversión del cobro {$aprobado->id}")
        ->and($log('solicitudes_reversion_cobro', $paraAprobar->id, AccionAuditoria::EDITAR)->sole()->detalle)->toBe('Reversión aprobada. Nota: Visto.')
        ->and($log('solicitudes_reversion_cobro', $paraRechazar->id, AccionAuditoria::EDITAR)->sole()->detalle)->toBe('Reversión rechazada. Nota: No corresponde.')
        ->and($log('cobros', $rechazado->id, AccionAuditoria::REVERTIR))->toHaveCount(0);

    // El Auditor ve los eventos sin el contenido; Gerencia (ve los cobros), con él.
    $auditor = reversionesCobroUsuario(PerfilesPredefinidos::AUDITOR);
    $this->actingAs($auditor)->get(route('admin.auditoria.show', $log('solicitudes_reversion_cobro', $paraAprobar->id, AccionAuditoria::CREAR)->sole()))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Cobros')->assertDontSee('Motivo aprobado inventado.');
    $this->get(route('admin.auditoria.show', $revertir))->assertOk()->assertSee('Contenido económico: se requiere permiso de lectura sobre Cobros');
    $this->actingAs($this->gerente)->get(route('admin.auditoria.show', $log('solicitudes_reversion_cobro', $paraAprobar->id, AccionAuditoria::CREAR)->sole()))->assertOk()
        ->assertDontSee('Contenido económico')->assertSee('Motivo aprobado inventado.');
});

test('un cobro revertido, sus prestaciones y las solicitudes no se editan ni se borran fuera de la reversión', function () {
    reversionesCobroAbrir($this->cajero);
    $cobro = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    // Sin los datos de la reversión, el modelo no deja pasar a REVERTIDO.
    expect(fn () => $cobro->forceFill(['estado_id' => Estado::idDe(Estado::REVERTIDO)])->save())->toThrow(RegistroEconomicoInmutable::class);
    reversionesCobroRevertir($this->cajero, $cobro)->assertSessionHas('status');
    $cobro = Cobro::with('prestaciones')->find($cobro->id);

    expect(fn () => $cobro->forceFill(['motivo_reversion' => 'Otro motivo.'])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $cobro->forceFill(['estado_id' => Estado::idDe(Estado::VIGENTE)])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $cobro->prestaciones->first()->forceFill(['revertido_en' => now()->addDay()])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => MovimientoCaja::where('tipo', MovimientoCaja::REVERSION)->first()->delete())->toThrow(RegistroEconomicoInmutable::class);

    $otro = reversionesCobroCobrar($this->cajero, [reversionesCobroPrestacion()], [[$this->efectivo, '100.000']]);
    reversionesCobroAbrir($this->otroCajero);
    reversionesCobroSolicitar($this->otroCajero, $otro)->assertSessionHas('status');
    $solicitud = SolicitudReversionCobro::sole();
    expect(fn () => $solicitud->update(['motivo' => 'Otro.']))->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $solicitud->delete())->toThrow(RegistroEconomicoInmutable::class);
});
