<?php

/*
 * Liquidaciones a aseguradoras (docs/liquidaciones-aseguradoras.md): el armado con y sin firma recibida (las sin firma quedan afuera y
 * se listan), el corte futuro, una sola abierta por aseguradora, la prestación que no entra dos veces, el total exacto, quitar una línea
 * con motivo, enviar, aprobar y devolver, que nadie apruebe la propia, el bloqueo de visación, firma y anulación de la prestación, la
 * anulación con y sin pagos, los pagos parciales con su saldo y estados, el pago mayor al saldo, la anulación de un pago, la planilla,
 * los permisos por perfil, la auditoría con montos ocultos y las particulares fuera de toda liquidación. Datos, precios y números
 * inventados. Prefijo "liquidaciones".
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\Estado;
use App\Models\LineaLiquidacion;
use App\Models\LineaVisacion;
use App\Models\Liquidacion;
use App\Models\LogAuditoria;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\PagoAseguradora;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\ResponsablePago;
use App\Models\User;
use App\Models\Visacion;
use App\Support\Convenios\AplicarVisacion;
use App\Support\Convenios\CalcularCobertura;
use App\Support\Convenios\FirmaRecibida;
use App\Support\Convenios\GestionCoberturas;
use App\Support\Convenios\GestionConvenios;
use App\Support\Liquidaciones\ArmarLiquidacion;
use App\Support\Liquidaciones\FlujoLiquidacion;
use App\Support\Liquidaciones\PagosAseguradora;
use App\Support\Liquidaciones\PlanillaLiquidacion;
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
    $this->gerente = liquidacionesUsuario(PerfilesPredefinidos::GERENCIA);
    $this->recepcion = liquidacionesUsuario(PerfilesPredefinidos::RECEPCION);
    $this->facturacion = liquidacionesUsuario(PerfilesPredefinidos::FACTURACION);
    $this->admin = User::factory()->conPerfiles([PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->sole()])->create();
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO, CalcularCobertura::HITO])
        ->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $this->consultaProc->id, null, 100000, Carbon::parse('2026-10-01'));

    $this->aseguradora = ResponsablePago::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Seguros de Prueba', 'nombres' => 'S.A.'])->id]);
    $this->convenio = GestionConvenios::alta($this->facturacion, $this->aseguradora->id, 'Convenio de prueba', Carbon::parse('2026-10-01'), null, ['Plan Oro']);
    GestionCoberturas::cargarCobertura($this->recepcion, $this->paciente, $this->convenio->planes()->sole()->id, 'AS-0001', 'TITULAR', Carbon::parse('2026-10-01'), null);
    $this->transferencia = MedioPago::where('codigo', 'TRANSFERENCIA')->sole();
    $this->turnos = 0;
});

afterEach(fn () => Carbon::setTestNow());

function liquidacionesUsuario(string $codigo): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
}

/**
 * Una prestación de Carmen Duarte por el flujo de la aplicación: turno de hoy, visación de la Consulta (APROBADO con ese copago) cargada
 * por Recepción, atención y finalización de la Dra. Benítez y, si se pide, la firma recibida. Sin copago: la aseguradora paga ₲ 100.000.
 */
function liquidacionesPrestacion(string $copago = LineaVisacion::NINGUNO, ?string $valor = null, bool $firma = true): Prestacion
{
    $n = ++test()->turnos;
    $turno = hcTurno(['hora_inicio' => sprintf('%02d:00', 7 + $n), 'hora_fin' => sprintf('%02d:30', 7 + $n), 'procedimiento_id' => test()->consultaProc->id]);
    GestionCoberturas::cargarVisacion(test()->recepcion, test()->paciente, 'turno', $turno->id, "V-{$n}", Carbon::parse('2026-10-06'),
        [['procedimiento_id' => test()->consultaProc->id, 'resultado' => LineaVisacion::APROBADO, 'copago' => $copago, 'valor' => $valor]]);

    return liquidacionesAtender($turno, $firma);
}

function liquidacionesAtender($turno, bool $firma = false): Prestacion
{
    test()->actingAs(test()->medico);
    hcGuardarNueva([], $turno)->assertSessionHasNoErrors();
    $prestacion = Prestacion::where('consulta_id', \App\Models\Consulta::latest('id')->value('id'))->whereNull('informe_estudio_id')->sole();
    if ($firma) {
        FirmaRecibida::marcar(test()->recepcion, $prestacion);
    }

    return $prestacion->fresh();
}

/** Facturación arma por la pantalla, con lo que mostró la vista previa. */
function liquidacionesArmar(string $corte = '06/10/2026', ?User $usuario = null)
{
    $usuario ??= test()->facturacion;
    $vista = test()->actingAs($usuario)->get(route('admin.liquidaciones.create', ['aseguradora' => test()->aseguradora->id, 'corte' => $corte]))->assertOk();
    $prestaciones = $vista->viewData('prestaciones') ?? collect();

    return test()->from(route('admin.liquidaciones.create'))->post(route('admin.liquidaciones.store'), [
        'responsable_pago_id' => test()->aseguradora->id, 'fecha_corte' => $corte, 'cantidad' => $prestaciones->count(), 'total' => $vista->viewData('total') ?? 0,
    ]);
}

/** Armada, enviada y aprobada por Gerencia. */
function liquidacionesAprobada(): Liquidacion
{
    liquidacionesArmar()->assertSessionHasNoErrors();
    $liquidacion = Liquidacion::latest('id')->firstOrFail();
    test()->post(route('admin.liquidaciones.enviar', $liquidacion))->assertSessionHas('status');
    test()->actingAs(test()->gerente)->post(route('admin.liquidaciones.aprobar', $liquidacion))->assertSessionHas('status');

    return $liquidacion->fresh();
}

function liquidacionesPagar(Liquidacion $liquidacion, string $monto)
{
    return test()->actingAs(test()->facturacion)->from(route('admin.liquidaciones.show', $liquidacion))->post(route('admin.liquidaciones.pagos.store', $liquidacion), [
        'monto' => $monto, 'fecha' => '06/10/2026', 'medio_pago_id' => test()->transferencia->id, 'referencia' => 'TR-0001',
    ]);
}

test('armar: entran las firmadas con su snapshot y el total exacto; las sin firma quedan afuera y se listan', function () {
    $completa = liquidacionesPrestacion();                                   // aseguradora 100.000
    $porcentaje = liquidacionesPrestacion(LineaVisacion::PORCENTAJE, '20');  // aseguradora 80.000
    $fijo = liquidacionesPrestacion(LineaVisacion::MONTO_FIJO, '30000');     // aseguradora 70.000
    $sinFirma = liquidacionesPrestacion(firma: false);

    $vista = $this->actingAs($this->facturacion)->get(route('admin.liquidaciones.create', ['aseguradora' => $this->aseguradora->id, 'corte' => '06/10/2026']))
        ->assertOk()->assertSee('Vista previa: 3 prestaciones, total ₲ 250.000')->assertSee('Quedan afuera por falta de firma: 1')->assertSee('Marcar la firma')
        ->assertSee('<form method="POST" action="'.route('admin.liquidaciones.store').'" novalidate', false);
    expect($vista->viewData('sinFirma')->pluck('id')->all())->toBe([$sinFirma->id]);

    liquidacionesArmar()->assertSessionHasNoErrors()->assertSessionHas('status');
    $liquidacion = Liquidacion::sole();
    expect($liquidacion->enPreparacion())->toBeTrue()
        ->and($liquidacion->totalEntero())->toBe(250000)
        ->and($liquidacion->totalEntero())->toBe($liquidacion->lineas->sum(fn ($linea) => $linea->montoEntero()))
        ->and($liquidacion->armada_por)->toBe($this->facturacion->id)
        ->and($liquidacion->convenio_id)->toBe($this->convenio->id)
        ->and($liquidacion->lineas->pluck('prestacion_id')->all())->toBe([$completa->id, $porcentaje->id, $fijo->id]);
    $linea = $liquidacion->lineas->first();
    expect($linea->paciente)->toContain('Carmen')->and($linea->numero_asegurado)->toBe('AS-0001')->and($linea->numero_visacion)->toBe('V-1')
        ->and($linea->procedimiento)->toBe('Consulta')->and($linea->fecha_servicio->format('Y-m-d'))->toBe('2026-10-06');

    // La sin firma sigue libre; firmada, entra en la liquidación siguiente.
    expect(ArmarLiquidacion::sinFirma($this->aseguradora->id)->pluck('prestaciones.id')->all())->toBe([$sinFirma->id]);
    $this->get(route('admin.liquidaciones.sin-firma'))->assertOk()->assertSee('1 prestación sin firma');
    $this->get(route('admin.liquidaciones.index'))->assertOk()->assertSee('Hay 1 prestación de aseguradora sin la firma recibida');
});

test('corte futuro rechazado y fecha de servicio posterior al corte fuera', function () {
    liquidacionesPrestacion();
    $this->actingAs($this->facturacion)->get(route('admin.liquidaciones.create', ['aseguradora' => $this->aseguradora->id, 'corte' => '07/10/2026']))
        ->assertOk()->assertSee(ArmarLiquidacion::CORTE_FUTURO);
    $this->post(route('admin.liquidaciones.store'), ['responsable_pago_id' => $this->aseguradora->id, 'fecha_corte' => '07/10/2026', 'cantidad' => 1, 'total' => 100000])
        ->assertSessionHas('error', ArmarLiquidacion::CORTE_FUTURO);
    // Con corte ayer, la de hoy no entra.
    liquidacionesArmar('05/10/2026')->assertSessionHas('error', ArmarLiquidacion::SIN_PRESTACIONES);
    expect(Liquidacion::count())->toBe(0);
});

test('una sola abierta por aseguradora, y la prestación no entra dos veces', function () {
    $prestacion = liquidacionesPrestacion();
    liquidacionesArmar()->assertSessionHasNoErrors();
    $primera = Liquidacion::sole();
    liquidacionesPrestacion();
    $this->actingAs($this->facturacion);
    expect(fn () => ArmarLiquidacion::ejecutar($this->facturacion, $this->aseguradora, Carbon::parse('2026-10-06')))
        ->toThrow(AccionRechazada::class, ArmarLiquidacion::YA_ABIERTA);
    $this->get(route('admin.liquidaciones.create', ['aseguradora' => $this->aseguradora->id, 'corte' => '06/10/2026']))->assertSee(ArmarLiquidacion::YA_ABIERTA);

    // El índice único parcial frena una segunda abierta y una segunda línea de la misma prestación aunque se saltee la verificación.
    expect(fn () => DB::table('liquidaciones')->insert(['responsable_pago_id' => $this->aseguradora->id, 'fecha_corte' => '2026-10-06', 'estado_id' => Estado::idDe(Estado::EN_PREPARACION),
        'total' => 0, 'moneda_id' => $primera->moneda_id, 'armada_en' => now(), 'armada_por' => $this->facturacion->id]))->toThrow(QueryException::class);
    expect(fn () => DB::table('lineas_liquidacion')->insert(['liquidacion_id' => $primera->id, 'prestacion_id' => $prestacion->id, 'paciente' => 'X', 'documento' => 'X',
        'numero_asegurado' => 'X', 'fecha_servicio' => '2026-10-06', 'procedimiento' => 'X', 'numero_visacion' => 'X', 'monto' => 1]))->toThrow(QueryException::class);

    // Aprobada la primera, la siguiente lleva solo la nueva.
    $this->post(route('admin.liquidaciones.enviar', $primera));
    $this->actingAs($this->gerente)->post(route('admin.liquidaciones.aprobar', $primera))->assertSessionHas('status');
    liquidacionesArmar()->assertSessionHasNoErrors();
    $segunda = Liquidacion::latest('id')->first();
    expect($segunda->id)->not->toBe($primera->id)->and($segunda->lineas()->pluck('prestacion_id')->all())->not->toContain($prestacion->id)
        ->and($segunda->lineas()->count())->toBe(1);
});

test('quitar una línea con motivo: el total baja y la prestación queda libre; solo en preparación', function () {
    $una = liquidacionesPrestacion();
    liquidacionesPrestacion(LineaVisacion::PORCENTAJE, '20');
    liquidacionesArmar()->assertSessionHasNoErrors();
    $liquidacion = Liquidacion::sole();
    $linea = $liquidacion->lineas()->where('prestacion_id', $una->id)->sole();

    $this->post(route('admin.liquidaciones.lineas.quitar', $linea), ['motivo_quitar' => 'no'])->assertSessionHasErrors('motivo_quitar');
    $this->post(route('admin.liquidaciones.lineas.quitar', $linea), ['motivo_quitar' => 'Visación observada'])->assertSessionHas('status');
    expect($linea->fresh()->quitada())->toBeTrue()->and($liquidacion->fresh()->totalEntero())->toBe(80000)
        ->and(ArmarLiquidacion::candidatas($this->aseguradora->id, Carbon::parse('2026-10-06'))->pluck('prestaciones.id')->all())->toBe([$una->id])
        ->and(LogAuditoria::where('tabla_afectada', 'lineas_liquidacion')->where('detalle', "Quitada de la liquidación N.º {$liquidacion->id}. Motivo: Visación observada")->exists())->toBeTrue();
    $this->get(route('admin.liquidaciones.show', $liquidacion))->assertOk()->assertSee('Quitada el')->assertSee('Visación observada');

    // Enviada, ya no se quita.
    $this->post(route('admin.liquidaciones.enviar', $liquidacion))->assertSessionHas('status');
    $otra = $liquidacion->lineas()->whereNull('liberada_en')->sole();
    $this->post(route('admin.liquidaciones.lineas.quitar', $otra), ['motivo_quitar' => 'Ya no corresponde'])->assertSessionHas('error');
    expect($otra->fresh()->liberada())->toBeFalse();
});

test('envío, devolución con nota (vuelve a preparación) y aprobación; nadie aprueba la propia', function () {
    liquidacionesPrestacion();
    liquidacionesArmar()->assertSessionHasNoErrors();
    $liquidacion = Liquidacion::sole();

    // Gerencia no aprueba una en preparación.
    $this->actingAs($this->gerente)->post(route('admin.liquidaciones.aprobar', $liquidacion))->assertSessionHas('error');
    $this->actingAs($this->facturacion)->post(route('admin.liquidaciones.enviar', $liquidacion))->assertSessionHas('status');
    expect($liquidacion->fresh()->pendienteDeAprobacion())->toBeTrue();
    // Facturación no tiene el permiso de aprobar.
    $this->post(route('admin.liquidaciones.aprobar', $liquidacion))->assertForbidden();

    $this->actingAs($this->gerente)->post(route('admin.liquidaciones.devolver', $liquidacion), ['nota_devolucion' => ''])->assertSessionHasErrors('nota_devolucion');
    $this->post(route('admin.liquidaciones.devolver', $liquidacion), ['nota_devolucion' => 'Falta una visación'])->assertSessionHas('status');
    $devuelta = $liquidacion->fresh();
    expect($devuelta->enPreparacion())->toBeTrue()->and($devuelta->nota_devolucion)->toBe('Falta una visación')->and($devuelta->devuelta_por)->toBe($this->gerente->id);

    $this->actingAs($this->facturacion)->post(route('admin.liquidaciones.enviar', $liquidacion))->assertSessionHas('status');
    $this->actingAs($this->gerente)->post(route('admin.liquidaciones.aprobar', $liquidacion))->assertSessionHas('status');
    $aprobada = $liquidacion->fresh();
    expect($aprobada->tieneEstado(Estado::APROBADO))->toBeTrue()->and($aprobada->aprobada_por)->toBe($this->gerente->id)
        ->and($aprobada->facturacion)->toBe(Liquidacion::PENDIENTE_DE_FACTURAR);
    $this->get(route('admin.liquidaciones.show', $liquidacion))->assertSee('Pendiente de facturar');

    // El Administrador que la armó tampoco la aprueba.
    liquidacionesPrestacion();
    liquidacionesArmar(usuario: $this->admin)->assertSessionHasNoErrors();
    $propia = Liquidacion::latest('id')->first();
    $this->actingAs($this->admin)->post(route('admin.liquidaciones.enviar', $propia))->assertSessionHas('status');
    $this->get(route('admin.liquidaciones.show', $propia))->assertSee('Usted armó esta liquidación')->assertDontSee('Aprobar la liquidación');
    $this->post(route('admin.liquidaciones.aprobar', $propia))->assertSessionHas('error', FlujoLiquidacion::PROPIA);
    $this->post(route('admin.liquidaciones.devolver', $propia), ['nota_devolucion' => 'Me la devuelvo'])->assertSessionHas('error', FlujoLiquidacion::PROPIA);
    expect($propia->fresh()->pendienteDeAprobacion())->toBeTrue();
});

test('aprobada, bloquea la visación, la firma y la anulación de sus prestaciones con un aviso claro', function () {
    $prestacion = liquidacionesPrestacion();
    liquidacionesArmar()->assertSessionHasNoErrors();
    $liquidacion = Liquidacion::sole();

    // En preparación: hay que quitarla primero.
    expect(AplicarVisacion::impedimento($prestacion))->toContain('en preparación: quítela de la liquidación');
    $liquidacion = liquidacionesAprobadaDesde($liquidacion);
    $aviso = "Esta prestación está en la liquidación N.º {$liquidacion->id}, aprobada: no se pueden cambiar su visación, su firma ni su monto.";

    $this->actingAs($this->recepcion)->from(route('admin.coberturas.show', $this->paciente))->post(route('admin.visaciones.aplicar', $prestacion), [
        'visacion_id' => 'PARTICULAR', 'motivo_aplicacion' => 'Pasarla a particular',
    ])->assertSessionHas('error', $aviso);
    $this->post(route('admin.visaciones.firma.desmarcar', $prestacion), ['motivo_firma' => 'Firmó otra persona'])->assertSessionHas('error', $aviso);
    expect(fn () => AnularPrestacion::ejecutar($this->gerente, $prestacion, 'Prestación duplicada'))->toThrow(AccionRechazada::class, $aviso);
    $actual = $prestacion->fresh();
    expect($actual->firma_recibida_en)->not->toBeNull()->and($actual->deAseguradora())->toBeTrue()->and($actual->anulada())->toBeFalse()
        ->and((int) $actual->monto_aseguradora)->toBe(100000);
    // La página de la paciente no ofrece cambiarla; la prestación muestra su liquidación a quien ve las liquidaciones.
    $this->get(route('admin.coberturas.show', $this->paciente))->assertOk()->assertSee('En una liquidación: su visación, su firma y su monto no cambian.');
    $this->actingAs($this->facturacion)->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertSee("liquidación N.º {$liquidacion->id}");
    $this->actingAs($this->recepcion)->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertDontSee(route('admin.liquidaciones.show', $liquidacion));
});

function liquidacionesAprobadaDesde(Liquidacion $liquidacion): Liquidacion
{
    test()->actingAs(test()->facturacion)->post(route('admin.liquidaciones.enviar', $liquidacion))->assertSessionHas('status');
    test()->actingAs(test()->gerente)->post(route('admin.liquidaciones.aprobar', $liquidacion))->assertSessionHas('status');

    return $liquidacion->fresh();
}

test('pagos parciales con saldo, mayor al saldo rechazado, PARCIAL y PAGADO; no tocan la Caja', function () {
    liquidacionesPrestacion();
    liquidacionesPrestacion(LineaVisacion::PORCENTAJE, '20');
    $liquidacion = liquidacionesAprobada(); // 180.000
    $cajas = [Caja::count(), Cobro::count(), MovimientoCaja::count()];

    liquidacionesPagar($liquidacion, '100.000')->assertSessionHas('status', 'Pago registrado: ₲ 100.000. Saldo: ₲ 80.000.');
    $liquidacion = $liquidacion->fresh();
    expect($liquidacion->tieneEstado(Estado::PARCIAL))->toBeTrue()->and($liquidacion->saldo())->toBe(80000);

    liquidacionesPagar($liquidacion, '80001')->assertSessionHas('error', 'El pago supera el saldo de la liquidación (₲ 80.000).');
    liquidacionesPagar($liquidacion, '0')->assertSessionHasErrors('monto');
    expect(PagoAseguradora::count())->toBe(1);

    liquidacionesPagar($liquidacion, '80000')->assertSessionHas('status');
    $liquidacion = $liquidacion->fresh();
    expect($liquidacion->tieneEstado(Estado::PAGADO))->toBeTrue()->and($liquidacion->saldo())->toBe(0)
        ->and([Caja::count(), Cobro::count(), MovimientoCaja::count()])->toBe($cajas);
    // Sin saldo, no recibe más pagos.
    liquidacionesPagar($liquidacion, '1')->assertSessionHas('error');
    $this->get(route('admin.liquidaciones.por-cobrar'))->assertOk()->assertSee('Seguros de Prueba')->assertSee('₲ 180.000');
});

test('anular un pago con motivo recalcula el estado; nunca se borra', function () {
    liquidacionesPrestacion();
    $liquidacion = liquidacionesAprobada(); // 100.000
    liquidacionesPagar($liquidacion, '100000')->assertSessionHas('status');
    $pago = PagoAseguradora::sole();
    expect($liquidacion->fresh()->tieneEstado(Estado::PAGADO))->toBeTrue();

    $this->post(route('admin.liquidaciones.pagos.anular', $pago), ['motivo_pago' => ''])->assertSessionHasErrors('motivo_pago');
    $this->post(route('admin.liquidaciones.pagos.anular', $pago), ['motivo_pago' => 'Monto mal cargado'])->assertSessionHas('status');
    expect($pago->fresh()->anulado())->toBeTrue()->and($pago->fresh()->motivo_anulacion)->toBe('Monto mal cargado')
        ->and($liquidacion->fresh()->tieneEstado(Estado::APROBADO))->toBeTrue()->and($liquidacion->fresh()->saldo())->toBe(100000)
        ->and(LogAuditoria::where('tabla_afectada', 'pagos_aseguradora')->where('accion', AccionAuditoria::ANULAR->value)->value('detalle'))->toBe('Pago anulado. Motivo: Monto mal cargado');
    $this->post(route('admin.liquidaciones.pagos.anular', $pago), ['motivo_pago' => 'Otra vez anulado'])->assertSessionHas('error');

    expect(fn () => $pago->fresh()->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $liquidacion->fresh()->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $liquidacion->fresh()->forceFill(['total' => 1])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => LineaLiquidacion::first()->forceFill(['monto' => 1])->save())->toThrow(RegistroEconomicoInmutable::class);
});

test('anular la liquidación: con pagos vigentes no; sin pagos sí, con motivo, y sus prestaciones quedan libres', function () {
    $prestacion = liquidacionesPrestacion();
    $liquidacion = liquidacionesAprobada();
    liquidacionesPagar($liquidacion, '50000')->assertSessionHas('status');

    // Facturación no anula una aprobada; Gerencia sí, pero no con pagos vigentes.
    $this->actingAs($this->facturacion)->post(route('admin.liquidaciones.anular', $liquidacion), ['motivo_anulacion' => 'Corte equivocado'])
        ->assertSessionHas('error', 'Una liquidación aprobada solo la anula quien aprueba las liquidaciones.');
    $this->actingAs($this->gerente)->post(route('admin.liquidaciones.anular', $liquidacion), ['motivo_anulacion' => 'Corte equivocado'])
        ->assertSessionHas('error', FlujoLiquidacion::CON_PAGOS);

    PagosAseguradora::anular($this->facturacion, PagoAseguradora::sole(), 'Pago de otra liquidación');
    $this->post(route('admin.liquidaciones.anular', $liquidacion), ['motivo_anulacion' => 'no'])->assertSessionHasErrors('motivo_anulacion');
    $this->post(route('admin.liquidaciones.anular', $liquidacion), ['motivo_anulacion' => 'Corte equivocado'])->assertSessionHas('status');
    $anulada = $liquidacion->fresh();
    expect($anulada->anulada())->toBeTrue()->and($anulada->motivo_anulacion)->toBe('Corte equivocado')->and($anulada->totalEntero())->toBe(100000)
        ->and($anulada->lineasVigentes()->count())->toBe(1)
        ->and(LineaLiquidacion::whereNull('liberada_en')->count())->toBe(0)
        ->and(AplicarVisacion::impedimento($prestacion->fresh()))->toBeNull()
        ->and(LogAuditoria::where('tabla_afectada', 'liquidaciones')->where('accion', AccionAuditoria::ANULAR->value)->value('detalle'))->toBe('Liquidación anulada. Motivo: Corte equivocado');

    // La prestación entra de nuevo en otra.
    liquidacionesArmar()->assertSessionHasNoErrors();
    expect(Liquidacion::latest('id')->first()->lineas()->pluck('prestacion_id')->all())->toBe([$prestacion->id]);
    // Facturación anula la que está en preparación.
    $this->post(route('admin.liquidaciones.anular', Liquidacion::latest('id')->first()), ['motivo_anulacion' => 'Se arma más adelante'])->assertSessionHas('status');
});

test('las particulares, las anuladas y las de otra aseguradora quedan fuera de toda liquidación', function () {
    $seguro = liquidacionesPrestacion();
    // Con cobertura pero sin visación: particular con aviso.
    $particular = liquidacionesAtender(hcTurno(['hora_inicio' => '15:00', 'hora_fin' => '15:30', 'procedimiento_id' => $this->consultaProc->id]));
    $anulada = liquidacionesPrestacion();
    AnularPrestacion::ejecutar($this->gerente, $anulada, 'Prestación duplicada');
    $sinParte = liquidacionesPrestacion(LineaVisacion::MONTO_FIJO, '100000'); // la paciente paga todo: nada a cargo de la aseguradora

    expect($particular->deAseguradora())->toBeFalse();
    liquidacionesArmar()->assertSessionHasNoErrors();
    expect(LineaLiquidacion::pluck('prestacion_id')->all())->toBe([$seguro->id])
        ->and(ArmarLiquidacion::sinFirma()->count())->toBe(0);

    $otra = ResponsablePago::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Otra Aseguradora', 'nombres' => 'S.A.'])->id]);
    GestionConvenios::alta($this->facturacion, $otra->id, 'Otro convenio', Carbon::parse('2026-10-01'), null, ['Plan Único']);
    expect(fn () => ArmarLiquidacion::ejecutar($this->facturacion, $otra, Carbon::parse('2026-10-06')))->toThrow(AccionRechazada::class, ArmarLiquidacion::SIN_PRESTACIONES);
    expect($sinParte->liquidacion)->toBeNull();
});

test('planilla: PDF al vuelo con las líneas y el total, sin nada clínico; con permiso y en Auditoría', function () {
    liquidacionesPrestacion();
    liquidacionesPrestacion(LineaVisacion::PORCENTAJE, '20');
    liquidacionesArmar()->assertSessionHasNoErrors();
    $liquidacion = Liquidacion::sole();

    $html = PlanillaLiquidacion::html($liquidacion);
    expect($html)->toContain('Seguros de Prueba')->toContain('Convenio de prueba')->toContain('Carmen')->toContain('AS-0001')->toContain('V-1')->toContain('V-2')
        ->toContain('06/10/2026')->toContain('Consulta')->toContain('₲ 100.000')->toContain('₲ 80.000')->toContain('₲ 180.000')
        ->toContain('Recibido por la aseguradora')
        ->not->toContain('Rinofaringitis')->not->toContain('J06.9')->not->toContain('Dolor de garganta')->not->toContain('Penicilina');

    foreach ([$this->facturacion, $this->gerente, $this->admin] as $usuario) {
        $respuesta = $this->actingAs($usuario)->get(route('admin.liquidaciones.planilla', $liquidacion))->assertOk();
        expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf')->and($respuesta->headers->get('Cache-Control'))->toContain('no-store');
    }
    expect(LogAuditoria::where('accion', AccionAuditoria::EXPORTAR->value)->where('tabla_afectada', 'liquidaciones')->count())->toBe(3);
    foreach ([$this->recepcion, $this->medico] as $usuario) {
        $this->actingAs($usuario)->get(route('admin.liquidaciones.planilla', $liquidacion))->assertForbidden();
    }
});

test('permisos: Facturación arma y paga, Gerencia aprueba y ve todo; Recepción, Caja, Médico, Enfermería y Compras no ven nada', function () {
    liquidacionesPrestacion();
    $liquidacion = liquidacionesAprobada();

    foreach ([$this->facturacion, $this->gerente, $this->admin] as $usuario) {
        $this->actingAs($usuario)->get(route('admin.liquidaciones.index'))->assertOk()->assertSee('Seguros de Prueba');
        $this->get(route('admin.liquidaciones.show', $liquidacion))->assertOk()->assertSee('₲ 100.000');
        $this->get(route('admin.liquidaciones.por-cobrar'))->assertOk();
        $this->get(route('admin.liquidaciones.sin-firma'))->assertOk();
        $this->get(route('dashboard'))->assertSee(route('admin.liquidaciones.index'));
    }
    // Gerencia no arma ni registra pagos.
    $this->actingAs($this->gerente)->get(route('admin.liquidaciones.create'))->assertForbidden();
    liquidacionesPagar($liquidacion, '1');
    $this->actingAs($this->gerente)->post(route('admin.liquidaciones.pagos.store', $liquidacion), ['monto' => '1', 'fecha' => '06/10/2026', 'medio_pago_id' => $this->transferencia->id])
        ->assertForbidden();

    $otros = [$this->recepcion, liquidacionesUsuario(PerfilesPredefinidos::CAJA), $this->medico, liquidacionesUsuario(PerfilesPredefinidos::ENFERMERIA),
        liquidacionesUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA)];
    foreach ($otros as $usuario) {
        $this->actingAs($usuario)->get(route('admin.liquidaciones.index'))->assertForbidden();
        $this->get(route('admin.liquidaciones.show', $liquidacion))->assertForbidden();
        $this->get(route('admin.liquidaciones.por-cobrar'))->assertForbidden();
        $this->post(route('admin.liquidaciones.store'), [])->assertForbidden();
        $this->post(route('admin.liquidaciones.pagos.store', $liquidacion), [])->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee(route('admin.liquidaciones.index'));
    }
});

test('auditoría: armado, envío, aprobación, devolución, pago y anulaciones; los montos se ocultan a quien no tiene el módulo', function () {
    liquidacionesPrestacion();
    liquidacionesArmar()->assertSessionHasNoErrors();
    $liquidacion = Liquidacion::sole();
    $this->post(route('admin.liquidaciones.enviar', $liquidacion));
    $this->actingAs($this->gerente)->post(route('admin.liquidaciones.devolver', $liquidacion), ['nota_devolucion' => 'Revise el corte']);
    $liquidacion = liquidacionesAprobadaDesde($liquidacion->fresh());
    liquidacionesPagar($liquidacion, '40000')->assertSessionHas('status');

    $log = fn (string $tabla, AccionAuditoria $accion = AccionAuditoria::CREAR) => LogAuditoria::where('tabla_afectada', $tabla)->where('accion', $accion->value)->orderBy('id')->get();
    expect($log('liquidaciones')->sole()->detalle)->toBe('Armado de la liquidación (1 prestaciones, corte al 06/10/2026)')
        ->and($log('lineas_liquidacion')->sole()->detalle)->toBe('Armado de la liquidación (1 prestaciones, corte al 06/10/2026)')
        ->and($log('liquidaciones', AccionAuditoria::EDITAR)->pluck('detalle')->all())->toBe([
            'Enviada a aprobación', 'Liquidación devuelta. Nota: Revise el corte', 'Enviada a aprobación', 'Liquidación aprobada', "Pago de la aseguradora, liquidación N.º {$liquidacion->id}",
        ])
        ->and($log('pagos_aseguradora')->sole()->detalle)->toBe("Pago de la aseguradora, liquidación N.º {$liquidacion->id}");

    // El Auditor ve los eventos sin los montos; Gerencia los ve.
    $auditor = liquidacionesUsuario(PerfilesPredefinidos::AUDITOR);
    $this->actingAs($auditor)->get(route('admin.auditoria.show', $log('liquidaciones')->sole()))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Liquidaciones a aseguradoras')->assertDontSee('100000');
    $this->get(route('admin.auditoria.show', $log('pagos_aseguradora')->sole()))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Pagos de aseguradoras')->assertDontSee('40000');
    $this->actingAs($this->gerente)->get(route('admin.auditoria.show', $log('liquidaciones')->sole()))->assertOk()->assertDontSee('Contenido económico')->assertSee('100000');
});
