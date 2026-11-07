<?php

/*
 * Tanda 3 de la liquidación a beneficiarios (docs/liquidacion-beneficiarios.md): la planilla PDF (la del profesional con la paciente,
 * la del dueño del equipo sin ella, nada clínico, EXPORTAR en Auditoría, no-store, no de una anulada), "Mis liquidaciones" (solo las
 * propias aprobadas, de solo lectura; 404 para las demás) y el servicio de consulta del objetivo 14 por beneficiario y período (con la
 * clínica: devengado y percibido) y su pestaña. Datos inventados. Prefijo "liqBenefConsulta".
 */

use App\Enums\AccionAuditoria;
use App\Models\AcuerdoReparto;
use App\Models\Caja;
use App\Models\Consulta;
use App\Models\Equipo;
use App\Models\LiquidacionBeneficiario;
use App\Models\LogAuditoria;
use App\Models\MedioPago;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Proveedor;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\Cobrar;
use App\Support\LiquidacionesBeneficiarios\ArmarLiquidacionBeneficiario;
use App\Support\LiquidacionesBeneficiarios\FlujoLiquidacionBeneficiario;
use App\Support\LiquidacionesBeneficiarios\PagosBeneficiario;
use App\Support\LiquidacionesBeneficiarios\PlanillaLiquidacionBeneficiario;
use App\Support\LiquidacionesBeneficiarios\ResumenBeneficiarios;
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
    $this->consultaProc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->eco = Procedimiento::create(['codigo' => 'ECO-PRUEBA', 'nombre' => 'Ecografía de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);
    $this->gerente = liqBenefConsultaUsuario(PerfilesPredefinidos::GERENCIA);
    $this->facturacion = liqBenefConsultaUsuario(PerfilesPredefinidos::FACTURACION);
    $this->tesoreria = liqBenefConsultaUsuario(PerfilesPredefinidos::COMPRAS_TESORERIA);
    $this->cajero = liqBenefConsultaUsuario(PerfilesPredefinidos::CAJA);
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO])->update(['fecha_hora' => '2026-10-01 00:00:00']);
    AltaTarifa::ejecutar($this->gerente, $this->consultaProc->id, null, 100000, Carbon::parse('2026-10-01'));
    AltaTarifa::ejecutar($this->gerente, $this->eco->id, null, 250000, Carbon::parse('2026-10-01'));
    AltaAcuerdo::ejecutar($this->gerente, AcuerdoReparto::PROFESIONAL, $this->profesional->id, null, null, Porcentaje::desdeTexto('40'), Carbon::parse('2026-10-01'));
    $this->duenio = Proveedor::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Equipos', 'nombres' => 'Dueño'])->id]);
    $equipo = Equipo::create(['nombre' => 'Ecógrafo de prueba', 'tipo_equipo_id' => TipoEquipo::create(['nombre' => 'Ecógrafo'])->id, 'proveedor_id' => $this->duenio->id]);
    $equipo->procedimientos()->sync([$this->eco->id]);
    AltaAcuerdo::ejecutar($this->gerente, AcuerdoReparto::EQUIPO, null, null, $equipo->id, Porcentaje::desdeTexto('20'), Carbon::parse('2026-10-01'));
    // La Dra. Benítez entra con el perfil Médico (que trae "Mis liquidaciones").
    $this->medico->perfiles()->attach(PerfilAcceso::where('codigo', PerfilesPredefinidos::MEDICO)->value('id'));
    $this->medico->olvidarPermisos();
    $this->turnos = 0;
});

afterEach(fn () => Carbon::setTestNow());

function liqBenefConsultaUsuario(string $codigo): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
}

function liqBenefConsultaPrestacion(bool $eco = false, bool $cobrar = true): Prestacion
{
    test()->actingAs(test()->medico);
    $n = ++test()->turnos;
    hcGuardarNueva([], hcTurno(['hora_inicio' => sprintf('%02d:00', 7 + $n), 'hora_fin' => sprintf('%02d:30', 7 + $n),
        'procedimiento_id' => $eco ? test()->eco->id : test()->consultaProc->id]))->assertSessionHasNoErrors();
    $prestacion = Prestacion::where('consulta_id', Consulta::latest('id')->value('id'))->whereNull('informe_estudio_id')->sole();
    if ($cobrar) {
        if (! Caja::where('usuario_id', test()->cajero->id)->exists()) {
            AbrirCaja::ejecutar(test()->cajero, 50000);
        }
        Cobrar::ejecutar(test()->cajero, test()->paciente->id, [$prestacion->id],
            [['medio_pago_id' => MedioPago::efectivo()->id, 'monto' => $prestacion->fresh()->montoPaciente(), 'referencia' => null]]);
    }

    return $prestacion;
}

function liqBenefConsultaAprobada(string $tipo, int $id): LiquidacionBeneficiario
{
    $liquidacion = ArmarLiquidacionBeneficiario::ejecutar(test()->facturacion, $tipo, $id, Carbon::parse('2026-10-06'));
    FlujoLiquidacionBeneficiario::enviar(test()->facturacion, $liquidacion);

    return FlujoLiquidacionBeneficiario::aprobar(test()->gerente, $liquidacion->fresh())->fresh();
}

test('planilla: la del profesional con la paciente; la del dueño del equipo solo con el N.º de prestación; nada clínico', function () {
    $eco = liqBenefConsultaPrestacion(eco: true);
    $profesional = liqBenefConsultaAprobada('PROFESIONAL', $this->profesional->id);
    $duenio = liqBenefConsultaAprobada('EQUIPO', $this->duenio->id);

    $html = PlanillaLiquidacionBeneficiario::html($profesional);
    expect($html)->toContain('Liquidación N.º '.$profesional->id.' a profesional')->toContain('Benítez, Rosa')->toContain('Duarte, Carmen')
        ->toContain('N.º '.$eco->id)->toContain('Ecografía de prueba')->not->toContain('Rinofaringitis')->not->toContain('J06.9');
    $html = PlanillaLiquidacionBeneficiario::html($duenio);
    expect($html)->toContain('a dueño de equipo')->toContain('Equipos, Dueño')->toContain('N.º '.$eco->id)->toContain('Ecógrafo de prueba')
        ->not->toContain('Duarte')->not->toContain('Carmen');

    $this->actingAs($this->tesoreria);
    $respuesta = $this->get(route('admin.liquidaciones-beneficiarios.planilla', $profesional))->assertOk();
    expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf')->and($respuesta->headers->get('Cache-Control'))->toContain('no-store')
        ->and(LogAuditoria::where('tabla_afectada', 'liquidaciones_beneficiario')->where('accion', AccionAuditoria::EXPORTAR->value)->value('detalle'))
        ->toBe("Planilla de la liquidación a beneficiario N.º {$profesional->id} (Benítez, Rosa, corte al 06/10/2026)");
    $this->actingAs($this->cajero)->get(route('admin.liquidaciones-beneficiarios.planilla', $profesional))->assertForbidden();

    FlujoLiquidacionBeneficiario::anular($this->gerente, $duenio, 'Se arma de nuevo');
    $this->actingAs($this->facturacion)->from(route('admin.liquidaciones-beneficiarios.show', $duenio))
        ->get(route('admin.liquidaciones-beneficiarios.planilla', $duenio))->assertSessionHas('error', 'Esta liquidación está anulada: no hay planilla para imprimir.');
});

test('Mis liquidaciones: el profesional ve solo las suyas aprobadas, de solo lectura, con la planilla; las demás dan 404', function () {
    liqBenefConsultaPrestacion();
    $this->actingAs($this->medico)->get(route('dashboard'))->assertSee('Mis liquidaciones');
    $abierta = ArmarLiquidacionBeneficiario::ejecutar($this->facturacion, 'PROFESIONAL', $this->profesional->id, Carbon::parse('2026-10-06'));

    $this->actingAs($this->medico)->get(route('admin.mis-liquidaciones.index'))->assertOk()->assertSee('Todavía no tiene liquidaciones aprobadas.');
    $this->get(route('admin.mis-liquidaciones.show', $abierta))->assertNotFound();
    $this->get(route('admin.liquidaciones-beneficiarios.show', $abierta))->assertForbidden();

    FlujoLiquidacionBeneficiario::enviar($this->facturacion, $abierta);
    FlujoLiquidacionBeneficiario::aprobar($this->gerente, $abierta->fresh());
    PagosBeneficiario::registrar($this->tesoreria, $abierta->fresh(), ['monto' => 15000, 'fecha' => Carbon::parse('2026-10-06'),
        'medio_pago_id' => MedioPago::where('codigo', MedioPago::TRANSFERENCIA)->value('id'), 'referencia' => 'TR-5']);
    $this->get(route('admin.mis-liquidaciones.index'))->assertOk()->assertSee('40.000')->assertSee('Pagada en parte');
    $this->get(route('admin.mis-liquidaciones.show', $abierta))->assertOk()->assertSee('Duarte, Carmen')->assertSee('TR-5')
        ->assertDontSee('Quitar (queda para la siguiente)')->assertDontSee('Registrar el pago')->assertDontSee('Anular el pago');
    $this->get(route('admin.mis-liquidaciones.planilla', $abierta))->assertOk();

    // La Dra. Insfrán (otra profesional) no la ve; la del dueño del equipo tampoco es "suya".
    $this->otroMedico->perfiles()->attach(PerfilAcceso::where('codigo', PerfilesPredefinidos::MEDICO)->value('id'));
    $this->otroMedico->olvidarPermisos();
    $this->actingAs($this->otroMedico)->get(route('admin.mis-liquidaciones.show', $abierta))->assertNotFound();
    $this->get(route('admin.mis-liquidaciones.planilla', $abierta))->assertNotFound();
    $this->actingAs($this->tesoreria)->get(route('admin.mis-liquidaciones.index'))->assertForbidden();
});

test('Mis liquidaciones: quien es profesional y dueño de equipo ve las dos, cada una con su rol; la del dueño sin la paciente; la ajena y la no aprobada, no', function () {
    // La Dra. Benítez es también la dueña del equipo.
    $this->duenio->update(['persona_id' => $this->medico->persona_id]);
    liqBenefConsultaPrestacion(eco: true);
    $profesional = liqBenefConsultaAprobada('PROFESIONAL', $this->profesional->id);
    $duenia = liqBenefConsultaAprobada('EQUIPO', $this->duenio->id);
    PagosBeneficiario::registrar($this->tesoreria, $duenia, ['monto' => 10000, 'fecha' => Carbon::parse('2026-10-06'),
        'medio_pago_id' => MedioPago::efectivo()->id, 'referencia' => null]);

    $this->actingAs($this->medico)->get(route('admin.mis-liquidaciones.index'))->assertOk()
        ->assertSeeInOrder(['Dueño de equipo', 'Profesional'])->assertSee('Pagada en parte');
    $this->get(route('admin.mis-liquidaciones.show', $duenia))->assertOk()->assertSee('Dueño de equipo')->assertSee('Ecógrafo de prueba')
        ->assertDontSee('Duarte')->assertDontSee('Carmen')->assertDontSee('Registrar el pago')->assertDontSee('Quitar (queda para la siguiente)');
    $this->get(route('admin.mis-liquidaciones.show', $profesional))->assertOk()->assertSee('Profesional')->assertSee('Duarte, Carmen');
    $this->get(route('admin.mis-liquidaciones.planilla', $duenia))->assertOk();
    expect(PlanillaLiquidacionBeneficiario::html($duenia))->not->toContain('Carmen');

    // Una nueva, sin aprobar, de la dueña: no aparece ni se abre.
    liqBenefConsultaPrestacion(eco: true);
    $abierta = ArmarLiquidacionBeneficiario::ejecutar($this->facturacion, 'EQUIPO', $this->duenio->id, Carbon::parse('2026-10-06'));
    $this->get(route('admin.mis-liquidaciones.show', $abierta))->assertNotFound();
    $this->get(route('admin.mis-liquidaciones.planilla', $abierta))->assertNotFound();
    expect($this->get(route('admin.mis-liquidaciones.index'))->viewData('liquidaciones')->pluck('id')->all())
        ->toEqualCanonicalizing([$profesional->id, $duenia->id]);

    // Otra profesional (sin ser dueña) no ve la del dueño de equipo.
    $this->otroMedico->perfiles()->attach(PerfilAcceso::where('codigo', PerfilesPredefinidos::MEDICO)->value('id'));
    $this->otroMedico->olvidarPermisos();
    $this->actingAs($this->otroMedico)->get(route('admin.mis-liquidaciones.show', $duenia))->assertNotFound();
    $this->get(route('admin.mis-liquidaciones.planilla', $duenia))->assertNotFound();
    expect($this->get(route('admin.mis-liquidaciones.index'))->viewData('liquidaciones')->count())->toBe(0);
});

test('el servicio de consulta del objetivo 14: devengado, percibido, liquidable, liquidado, pagado y saldo por beneficiario, con la clínica', function () {
    liqBenefConsultaPrestacion(eco: true);              // 250.000: profesional 100.000, dueño 50.000, clínica 100.000 (cobrada)
    liqBenefConsultaPrestacion(cobrar: false);          // 100.000: profesional 40.000, clínica 60.000 (sin cobrar)
    $fila = fn (string $clave) => ResumenBeneficiarios::periodo(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))->firstWhere('clave', $clave);

    expect($fila('CLINICA'))->toMatchArray(['beneficiario' => 'Clínica', 'devengado' => 160000, 'percibido' => 100000, 'liquidable' => null, 'liquidado' => null, 'pagado' => null, 'saldo' => null])
        ->and($fila('PROFESIONAL:'.$this->profesional->id))->toMatchArray(['devengado' => 140000, 'percibido' => 100000, 'liquidable' => 100000, 'liquidado' => 0, 'pagado' => 0, 'saldo' => 0])
        ->and($fila('EQUIPO:'.$this->duenio->id))->toMatchArray(['devengado' => 50000, 'percibido' => 50000, 'liquidable' => 50000]);

    $liquidacion = liqBenefConsultaAprobada('PROFESIONAL', $this->profesional->id);
    PagosBeneficiario::registrar($this->tesoreria, $liquidacion, ['monto' => 30000, 'fecha' => Carbon::parse('2026-10-06'),
        'medio_pago_id' => MedioPago::efectivo()->id, 'referencia' => null]);
    expect($fila('PROFESIONAL:'.$this->profesional->id))->toMatchArray(['liquidable' => 0, 'liquidado' => 100000, 'pagado' => 30000, 'saldo' => 70000])
        // Fuera del período, nada.
        ->and(ResumenBeneficiarios::periodo(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))->firstWhere('clave', 'CLINICA'))->toBeNull();

    $this->actingAs($this->facturacion)->get(route('admin.liquidaciones-beneficiarios.resumen', ['desde' => '01/10/2026', 'hasta' => '31/10/2026']))->assertOk()
        ->assertSee('Clínica')->assertSee('160.000')->assertSee('Benítez, Rosa')->assertSee('70.000')->assertSee('no es su ganancia');
    $this->get(route('admin.liquidaciones-beneficiarios.resumen', ['desde' => '31/10/2026', 'hasta' => '01/10/2026']))->assertSee('no puede ser posterior');
});
