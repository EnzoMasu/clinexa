<?php

/*
 * Las pantallas de la liquidación a beneficiarios (docs/liquidacion-beneficiarios.md): armar con la vista previa y confirmar, el
 * detalle con todas las acciones por la pantalla (quitar, enviar, aprobar, devolver, pagar con y sin caja, anular el pago y la
 * liquidación), "Por pagar por beneficiario", "Pendientes de liquidar" con el motivo, la trazabilidad desde la prestación, los permisos
 * por perfil (Tesorería paga y abre su caja, no arma ni cobra; Médico, Recepción, Caja y Auditor no entran) y la auditoría con los montos
 * ocultos al Auditor. Datos inventados. Prefijo "liqBenefPantallas".
 */

use App\Enums\AccionAuditoria;
use App\Models\AcuerdoReparto;
use App\Models\Caja;
use App\Models\CategoriaGasto;
use App\Models\Consulta;
use App\Models\LiquidacionBeneficiario;
use App\Models\LogAuditoria;
use App\Models\MedioPago;
use App\Models\PagoBeneficiario;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\Cobrar;
use App\Support\LiquidacionesBeneficiarios\Elegibilidad;
use App\Support\LiquidacionesBeneficiarios\FlujoLiquidacionBeneficiario;
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
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    (new MediosPagoSeeder)->run();
    $proc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->gerente = liqBenefPantallasUsuario(PerfilesPredefinidos::GERENCIA);
    $this->facturacion = liqBenefPantallasUsuario(PerfilesPredefinidos::FACTURACION);
    $this->tesoreria = liqBenefPantallasUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $this->cajero = liqBenefPantallasUsuario(PerfilesPredefinidos::CAJA);
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $proc->id, null, 100000, Carbon::parse('2026-10-01'));
    AltaAcuerdo::ejecutar($this->gerente, AcuerdoReparto::PROFESIONAL, $this->profesional->id, null, null, Porcentaje::desdeTexto('40'), Carbon::parse('2026-10-01'));
    $this->clave = 'PROFESIONAL:'.$this->profesional->id;
    $this->categoria = CategoriaGasto::create(['nombre' => 'Honorarios profesionales']);
});

afterEach(fn () => Carbon::setTestNow());

function liqBenefPantallasUsuario(string $codigo): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
}

/** Una prestación de la Dra. Benítez (₲ 100.000; su 40 %, ₲ 40.000), cobrada si se pide. */
function liqBenefPantallasPrestacion(bool $cobrar = true): Prestacion
{
    test()->actingAs(test()->medico);
    hcConsulta();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->sole();
    if ($cobrar) {
        if (! Caja::where('usuario_id', test()->cajero->id)->exists()) {
            AbrirCaja::ejecutar(test()->cajero, 50000);
        }
        Cobrar::ejecutar(test()->cajero, test()->paciente->id, [$prestacion->id], [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => 100000, 'referencia' => null]]);
    }

    return $prestacion;
}

/** Facturación arma por la pantalla, con lo que mostró la vista previa. */
function liqBenefPantallasArmar(?User $usuario = null)
{
    $usuario ??= test()->facturacion;
    $vista = test()->actingAs($usuario)->get(route('admin.liquidaciones-beneficiarios.create', ['beneficiario' => test()->clave, 'corte' => '06/10/2026']))->assertOk();
    $previa = $vista->viewData('previa');

    return test()->from(route('admin.liquidaciones-beneficiarios.create'))->post(route('admin.liquidaciones-beneficiarios.store'), [
        'beneficiario' => test()->clave, 'fecha_corte' => '06/10/2026', 'cantidad' => $previa['cantidad'], 'total' => $previa['total'],
    ]);
}

function liqBenefPantallasAprobada(): LiquidacionBeneficiario
{
    liqBenefPantallasArmar()->assertSessionHasNoErrors();
    $liquidacion = LiquidacionBeneficiario::latest('id')->firstOrFail();
    test()->post(route('admin.liquidaciones-beneficiarios.enviar', $liquidacion))->assertSessionHas('status');
    test()->actingAs(test()->gerente)->post(route('admin.liquidaciones-beneficiarios.aprobar', $liquidacion))->assertSessionHas('status');

    return $liquidacion->fresh();
}

test('armar por la pantalla: la vista previa con la paciente, confirmar y el detalle; la que cambió pide revisarla', function () {
    $prestacion = liqBenefPantallasPrestacion();

    $this->actingAs($this->facturacion)->get(route('admin.liquidaciones-beneficiarios.create', ['beneficiario' => $this->clave, 'corte' => '06/10/2026']))->assertOk()
        ->assertSee('Vista previa: 1 línea')->assertSee('Duarte, Carmen')->assertSee('Confirmar y armar la liquidación')
        ->assertSee('<form method="POST" action="'.route('admin.liquidaciones-beneficiarios.store').'" novalidate', false);
    $this->get(route('admin.liquidaciones-beneficiarios.create', ['beneficiario' => $this->clave, 'corte' => '07/10/2026']))->assertSee('La fecha de corte no puede ser posterior a hoy.');
    $this->from(route('admin.liquidaciones-beneficiarios.create'))->post(route('admin.liquidaciones-beneficiarios.store'), [
        'beneficiario' => $this->clave, 'fecha_corte' => '06/10/2026', 'cantidad' => 2, 'total' => 80000])->assertSessionHas('error', 'La lista cambió desde la vista previa: revísela y confirme de nuevo.');

    $respuesta = liqBenefPantallasArmar();
    $liquidacion = LiquidacionBeneficiario::sole();
    $respuesta->assertRedirect(route('admin.liquidaciones-beneficiarios.show', $liquidacion));
    $this->get(route('admin.liquidaciones-beneficiarios.show', $liquidacion))->assertOk()->assertSee('Benítez, Rosa')->assertSee('En preparación')
        ->assertSee('Enviar a aprobación')->assertSee("N.º {$prestacion->id}");
    $this->get(route('admin.liquidaciones-beneficiarios.index'))->assertOk()->assertSee('Benítez, Rosa');
    $this->get(route('admin.liquidaciones-beneficiarios.create', ['beneficiario' => $this->clave, 'corte' => '06/10/2026']))->assertSee('ya tiene una liquidación abierta');
});

test('el flujo por la pantalla: quitar, enviar, Facturación no aprueba, Gerencia devuelve y aprueba; nadie aprueba lo propio', function () {
    liqBenefPantallasPrestacion();
    liqBenefPantallasPrestacion();
    liqBenefPantallasArmar();
    $liquidacion = LiquidacionBeneficiario::sole();
    $linea = $liquidacion->lineas()->first();

    $this->post(route('admin.liquidaciones-beneficiarios.lineas.quitar', $linea), ['motivo_quitar' => 'Se posterga'])->assertSessionHas('status');
    expect($liquidacion->fresh()->totalEntero())->toBe(40000);
    $this->post(route('admin.liquidaciones-beneficiarios.enviar', $liquidacion))->assertSessionHas('status');
    $this->post(route('admin.liquidaciones-beneficiarios.aprobar', $liquidacion))->assertForbidden();

    $this->actingAs($this->gerente)->get(route('admin.liquidaciones-beneficiarios.show', $liquidacion))->assertSee('Aprobar la liquidación');
    $this->post(route('admin.liquidaciones-beneficiarios.devolver', $liquidacion), ['nota_devolucion' => 'Revisar'])->assertSessionHas('status');
    $this->actingAs($this->facturacion)->post(route('admin.liquidaciones-beneficiarios.enviar', $liquidacion))->assertSessionHas('status');
    $this->actingAs($this->gerente)->post(route('admin.liquidaciones-beneficiarios.aprobar', $liquidacion))->assertSessionHas('status');
    expect($liquidacion->fresh()->etiquetaEstado())->toBe('Aprobada');

    // Lo que arma Gerencia (la línea postergada) no lo aprueba Gerencia.
    liqBenefPantallasArmar($this->gerente)->assertSessionHasNoErrors();
    $propia = LiquidacionBeneficiario::latest('id')->firstOrFail();
    $this->post(route('admin.liquidaciones-beneficiarios.enviar', $propia))->assertSessionHas('status');
    $this->get(route('admin.liquidaciones-beneficiarios.show', $propia))->assertSee('Usted armó esta liquidación')->assertDontSee('Aprobar la liquidación');
    $this->post(route('admin.liquidaciones-beneficiarios.aprobar', $propia))->assertSessionHas('error', FlujoLiquidacionBeneficiario::PROPIA);
});

test('Tesorería paga por la pantalla (sin caja con comprobante, y en efectivo desde su caja) y anula; no arma', function () {
    liqBenefPantallasPrestacion();
    $liquidacion = liqBenefPantallasAprobada();
    $transferencia = MedioPago::where('codigo', MedioPago::TRANSFERENCIA)->sole();
    $this->actingAs($this->tesoreria);

    $this->get(route('admin.liquidaciones-beneficiarios.create'))->assertForbidden();
    $this->get(route('admin.liquidaciones-beneficiarios.show', $liquidacion))->assertOk()->assertSee('Registrar el pago')->assertSee('abra la suya en Cajas');
    $this->from(route('admin.liquidaciones-beneficiarios.show', $liquidacion))->post(route('admin.liquidaciones-beneficiarios.pagos.store', $liquidacion), [
        'monto' => '10.000', 'fecha' => '06/10/2026', 'medio_pago_id' => $transferencia->id, 'referencia' => ''])->assertSessionHas('error', $transferencia->mensajeComprobanteFaltante());
    $this->post(route('admin.liquidaciones-beneficiarios.pagos.store', $liquidacion), [
        'monto' => '10.000', 'fecha' => '06/10/2026', 'medio_pago_id' => $transferencia->id, 'referencia' => 'TR-77'])->assertSessionHas('status');

    // Abre su caja (sin poder cobrar) y paga el resto en efectivo desde ella.
    $this->post(route('admin.cajas.abrir'), ['monto_inicial' => '100.000'])->assertSessionHas('status');
    $this->get(route('admin.cobros.create'))->assertForbidden();
    $caja = Caja::where('usuario_id', $this->tesoreria->id)->sole();
    $this->get(route('admin.liquidaciones-beneficiarios.show', $liquidacion))->assertSee("Caja N.º {$caja->id}");
    $this->post(route('admin.liquidaciones-beneficiarios.pagos.store', $liquidacion), [
        'monto' => '30.000', 'fecha' => '06/10/2026', 'medio_pago_id' => MedioPago::efectivo()->id, 'referencia' => 'REC-1',
        'caja_id' => $caja->id, 'categoria_gasto_id' => $this->categoria->id])->assertSessionHas('status');
    $pago = PagoBeneficiario::whereNotNull('salida_caja_id')->sole();
    expect($liquidacion->fresh()->etiquetaEstado())->toBe('Pagada')->and($caja->fresh()->esperadoPorMedio()[MedioPago::efectivo()->id])->toBe(70000);
    $this->get(route('admin.cajas.show', $caja))->assertOk()->assertSee('Benítez, Rosa');

    $this->post(route('admin.liquidaciones-beneficiarios.pagos.anular', $pago), ['motivo_pago' => 'Monto equivocado'])->assertSessionHas('status');
    expect($pago->fresh()->anulado())->toBeTrue()->and($liquidacion->fresh()->etiquetaEstado())->toBe('Pagada en parte');
});

test('por pagar, pendientes con el motivo y la trazabilidad desde la prestación', function () {
    $cobrada = liqBenefPantallasPrestacion();
    $sinCobrar = liqBenefPantallasPrestacion(cobrar: false);
    $this->actingAs($this->facturacion)->get(route('admin.liquidaciones-beneficiarios.pendientes'))->assertOk()
        ->assertSee('Listas para liquidar')->assertSee('Benítez, Rosa')->assertSee(Elegibilidad::SIN_COBRO)->assertSee("N.º {$sinCobrar->id}");

    $liquidacion = liqBenefPantallasAprobada();
    $this->actingAs($this->facturacion)->get(route('admin.liquidaciones-beneficiarios.por-pagar'))->assertOk()
        ->assertSee('Benítez, Rosa')->assertSee("N.º {$liquidacion->id}")->assertSee('40.000');
    $this->actingAs($this->gerente)->get(route('admin.prestaciones.show', $cobrada))->assertOk()
        ->assertSee('Liquidaciones a beneficiarios')->assertSee("N.º {$liquidacion->id}");
});

test('permisos: quién entra a cada pantalla y quién ve el menú', function () {
    liqBenefPantallasPrestacion();
    $liquidacion = liqBenefPantallasAprobada();
    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::CAJA, PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::AUDITOR, PerfilesPredefinidos::ENFERMERIA] as $perfil) {
        $this->actingAs(liqBenefPantallasUsuario($perfil));
        $this->get(route('admin.liquidaciones-beneficiarios.index'))->assertForbidden();
        $this->get(route('admin.liquidaciones-beneficiarios.show', $liquidacion))->assertForbidden();
    }
    foreach ([$this->facturacion, $this->gerente, $this->tesoreria] as $usuario) {
        $this->actingAs($usuario)->get(route('dashboard'))->assertSee('Liquidaciones a beneficiarios');
        $this->get(route('admin.liquidaciones-beneficiarios.por-pagar'))->assertOk();
    }
    $this->actingAs($this->tesoreria)->post(route('admin.liquidaciones-beneficiarios.anular', $liquidacion), ['motivo_anulacion' => 'Prueba'])->assertForbidden();
    // Facturación no anula la aprobada: solo quien aprueba.
    $this->actingAs($this->facturacion)->post(route('admin.liquidaciones-beneficiarios.anular', $liquidacion), ['motivo_anulacion' => 'Prueba'])
        ->assertSessionHas('error', 'Una liquidación aprobada solo la anula quien aprueba las liquidaciones.');
    $this->actingAs($this->gerente)->post(route('admin.liquidaciones-beneficiarios.anular', $liquidacion), ['motivo_anulacion' => 'Se arma de nuevo'])->assertSessionHas('status');
});

test('auditoría: armado, envío, aprobación, pago y anulación con su detalle; los montos se ocultan al Auditor', function () {
    liqBenefPantallasPrestacion();
    $liquidacion = liqBenefPantallasAprobada();
    $this->actingAs($this->tesoreria)->post(route('admin.liquidaciones-beneficiarios.pagos.store', $liquidacion), [
        'monto' => '40.000', 'fecha' => '06/10/2026', 'medio_pago_id' => MedioPago::efectivo()->id])->assertSessionHas('status');
    $this->post(route('admin.liquidaciones-beneficiarios.pagos.anular', PagoBeneficiario::sole()), ['motivo_pago' => 'Pago mal cargado'])->assertSessionHas('status');

    $log = fn (string $tabla, AccionAuditoria $accion = AccionAuditoria::CREAR) => LogAuditoria::where('tabla_afectada', $tabla)->where('accion', $accion->value)->orderBy('id')->get();
    expect($log('liquidaciones_beneficiario')->sole()->detalle)->toBe('Armado de la liquidación a beneficiario (1 líneas, corte al 06/10/2026)')
        ->and($log('lineas_liquidacion_beneficiario'))->toHaveCount(1)
        ->and($log('liquidaciones_beneficiario', AccionAuditoria::EDITAR)->pluck('detalle')->all())
        ->toContain('Enviada a aprobación', 'Liquidación a beneficiario aprobada', 'Pago al beneficiario, liquidación N.º '.$liquidacion->id)
        ->and($log('pagos_beneficiario', AccionAuditoria::ANULAR)->sole()->detalle)->toBe('Pago al beneficiario anulado. Motivo: Pago mal cargado');

    $auditor = liqBenefPantallasUsuario(PerfilesPredefinidos::AUDITOR);
    $this->actingAs($auditor)->get(route('admin.auditoria.show', $log('liquidaciones_beneficiario')->sole()))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Liquidaciones a beneficiarios')->assertDontSee('40000');
    $this->get(route('admin.auditoria.show', $log('pagos_beneficiario')->sole()))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Pagos a beneficiarios');
    $this->actingAs($this->gerente)->get(route('admin.auditoria.show', $log('liquidaciones_beneficiario')->sole()))->assertOk()->assertDontSee('Contenido económico')->assertSee('40000');
});
