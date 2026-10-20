<?php

/*
 * Tarifas y prestaciones (docs/tarifas-prestaciones.md): la tarifa del profesional antes que la general, el cambio que cierra la
 * vigente, la prestación al finalizar (con y sin tarifa, una por consulta, la adenda no duplica), tarifar una pendiente, la
 * anulación con motivo, los permisos (Médico y Enfermería sin montos) y la auditoría. Datos y precios inventados.
 */

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LogAuditoria;
use App\Models\PerfilAcceso;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Tarifa;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Tarifas\AltaTarifa;
use App\Support\Tarifas\CambiarTarifa;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    hcEscenario(); // "hoy": martes 06/10/2026, 09:00 en Paraguay
    PerfilesPredefinidos::asegurar();
    $this->consultaProc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->eco = Procedimiento::create(['codigo' => 'ECO-PRUEBA', 'nombre' => 'Ecografía de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);
    $this->gerente = tarifasPrestacionesUsuario(PerfilesPredefinidos::GERENCIA, ['apellidos' => 'Gómez', 'nombres' => 'Ana']);
    // La migración guardó la hora real en que corrió; el "hoy" de estos tests es anterior: el módulo empieza el 01/10/2026.
    DB::table('hitos_sistema')->where('codigo', GenerarPrestacion::HITO)->update(['fecha_hora' => '2026-10-01 00:00:00']);
});

/** Una consulta finalizada que quedó sin prestación (como si hubiera fallado la creación). */
function tarifasPrestacionesConsultaSinPrestacion(): Consulta
{
    $consulta = hcConsulta();
    DB::table('prestaciones')->where('consulta_id', $consulta->id)->delete();

    return $consulta;
}

afterEach(fn () => Carbon::setTestNow());

/** Un usuario con el perfil predefinido de ese código. */
function tarifasPrestacionesUsuario(string $codigo, array $persona = []): User
{
    $factory = User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()]);

    return ($persona ? $factory->conPersona($persona) : $factory)->create();
}

/** Una tarifa cargada por la gerente (sin pasar por la pantalla). */
function tarifasPrestacionesTarifa(int $monto, ?Profesional $profesional = null, string $desde = '2026-10-01', ?Procedimiento $procedimiento = null): Tarifa
{
    return AltaTarifa::ejecutar(test()->gerente, ($procedimiento ?? test()->consultaProc)->id, $profesional?->id, $monto, Carbon::parse($desde));
}

/** La prestación de la consulta. */
function tarifasPrestacionesDe(Consulta $consulta): ?Prestacion
{
    return Prestacion::where('consulta_id', $consulta->id)->first();
}

test('al finalizar se usa la tarifa del profesional y, si no tiene, la general del procedimiento', function () {
    tarifasPrestacionesTarifa(100000);
    $propia = tarifasPrestacionesTarifa(150000, $this->profesional);

    $prestacion = tarifasPrestacionesDe(hcConsulta());
    expect($prestacion->tieneEstado(Estado::TARIFADO))->toBeTrue()
        ->and((int) $prestacion->monto)->toBe(150000)
        ->and($prestacion->tarifa_id)->toBe($propia->id)
        ->and($prestacion->procedimiento_id)->toBe($this->consultaProc->id)
        ->and($prestacion->paciente_id)->toBe($this->paciente->id)
        ->and($prestacion->fecha_servicio->format('Y-m-d'))->toBe('2026-10-06')
        ->and($prestacion->turno_id)->toBeNull();

    $this->actingAs($this->otroMedico);
    $otra = tarifasPrestacionesDe(hcConsulta());
    expect((int) $otra->monto)->toBe(100000)->and($otra->tarifa->esGeneral())->toBeTrue();
});

test('con turno se usa el procedimiento del turno, y una tarifa futura todavía no se aplica', function () {
    tarifasPrestacionesTarifa(300000, procedimiento: $this->eco, desde: '2026-10-07');
    $turno = hcTurno(['procedimiento_id' => $this->eco->id]);

    hcGuardarNueva([], $turno)->assertSessionHasNoErrors();

    $prestacion = Prestacion::sole();
    expect($prestacion->turno_id)->toBe($turno->id)->and($prestacion->procedimiento_id)->toBe($this->eco->id)
        ->and($prestacion->pendiente())->toBeTrue()->and($prestacion->monto)->toBeNull();
});

test('sin tarifa la consulta se finaliza igual y la prestación queda pendiente de tarifar', function () {
    $consulta = hcConsulta();

    $prestacion = tarifasPrestacionesDe($consulta);
    expect($consulta->fresh()->finalizada())->toBeTrue()
        ->and($prestacion->pendiente())->toBeTrue()->and($prestacion->etiquetaEstado())->toBe('Pendiente de tarifar')
        ->and($prestacion->monto)->toBeNull()->and($prestacion->tarifa_id)->toBeNull()->and($prestacion->moneda_id)->toBeNull();
});

test('sin el procedimiento Consulta queda pendiente y sin procedimiento; no se puede tarifar', function () {
    config(['clinexa.prestaciones.procedimiento_consulta' => 'NO-EXISTE']);
    tarifasPrestacionesTarifa(100000);

    $prestacion = tarifasPrestacionesDe(hcConsulta());
    expect($prestacion->pendiente())->toBeTrue()->and($prestacion->procedimiento_id)->toBeNull();

    $this->actingAs($this->gerente)->post(route('admin.prestaciones.tarifar', $prestacion))->assertSessionHas('error');
    expect($prestacion->fresh()->pendiente())->toBeTrue();
});

test('si la prestación falla, la consulta se finaliza igual y queda un evento FALLO en Auditoría, sin contenido clínico', function () {
    Prestacion::creating(fn () => throw new RuntimeException('Falla de prueba'));

    $consulta = hcConsulta();

    expect($consulta->fresh()->finalizada())->toBeTrue()->and(Prestacion::count())->toBe(0);
    $evento = LogAuditoria::where('accion', AccionAuditoria::FALLO->value)->sole();
    expect($evento->tabla_afectada)->toBe('prestaciones')->and($evento->usuario_id)->toBe($this->medico->id)
        ->and($evento->valor_nuevo)->toBe(['consulta_id' => $consulta->id, 'profesional_id' => $this->profesional->id, 'fecha_servicio' => '2026-10-06'])
        ->and($evento->detalle)->toContain("consulta {$consulta->id}")->not->toContain('garganta');
    expect(GenerarPrestacion::sinPrestacion()->pluck('id')->all())->toBe([$consulta->id]);
});

test('consultas sin prestación: Gerencia las ve con el aviso y "Generar" usa la tarifa del día sin duplicar', function () {
    tarifasPrestacionesTarifa(100000);
    $consulta = tarifasPrestacionesConsultaSinPrestacion();
    $this->actingAs($this->gerente);

    $this->get(route('admin.prestaciones.index'))->assertOk()->assertSee('Hay 1 consulta finalizada sin prestación.')
        ->assertSee('Consultas sin prestación')->assertSee(route('admin.prestaciones.generar', $consulta));

    $this->post(route('admin.prestaciones.generar', $consulta))->assertSessionHasNoErrors()->assertSessionHas('status');
    $prestacion = tarifasPrestacionesDe($consulta);
    expect((int) $prestacion->monto)->toBe(100000)->and($prestacion->fecha_servicio->format('Y-m-d'))->toBe('2026-10-06');

    $this->post(route('admin.prestaciones.generar', $consulta))->assertSessionHas('error');
    expect(Prestacion::where('consulta_id', $consulta->id)->count())->toBe(1);
    $this->get(route('admin.prestaciones.index'))->assertOk()->assertDontSee('sin prestación.')->assertDontSee('Consultas sin prestación');
});

test('las consultas finalizadas antes de la migración no aparecen ni se generan', function () {
    $consulta = tarifasPrestacionesConsultaSinPrestacion(); // finalizada el 06/10/2026 a las 12:00 UTC
    DB::table('hitos_sistema')->where('codigo', GenerarPrestacion::HITO)->update(['fecha_hora' => '2026-10-06 13:00:00']);
    $this->actingAs($this->gerente);

    expect(GenerarPrestacion::sinPrestacion()->count())->toBe(0);
    $this->get(route('admin.prestaciones.index'))->assertOk()->assertDontSee('Consultas sin prestación')->assertDontSee('sin prestación.');
    $this->post(route('admin.prestaciones.generar', $consulta))->assertSessionHas('error');
    expect(Prestacion::count())->toBe(0);
});

test('solo quien administra tarifas ve la lista y genera; los demás ven solo la cantidad', function () {
    $consulta = tarifasPrestacionesConsultaSinPrestacion();

    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::CAJA, PerfilesPredefinidos::FACTURACION] as $codigo) {
        $this->actingAs(tarifasPrestacionesUsuario($codigo));
        $this->get(route('admin.prestaciones.index'))->assertOk()->assertSee('Hay 1 consulta finalizada sin prestación.')
            ->assertDontSee('Consultas sin prestación')->assertDontSee(route('admin.prestaciones.generar', $consulta));
        $this->post(route('admin.prestaciones.generar', $consulta))->assertForbidden();
    }
    foreach ([PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA] as $codigo) {
        $this->actingAs(tarifasPrestacionesUsuario($codigo))->post(route('admin.prestaciones.generar', $consulta))->assertForbidden();
    }
    expect(Prestacion::count())->toBe(0);

    $this->actingAs(User::factory()->administrador()->create())->post(route('admin.prestaciones.generar', $consulta))->assertSessionHasNoErrors();
    expect(Prestacion::where('consulta_id', $consulta->id)->count())->toBe(1);
});

test('una sola prestación por consulta: ni un segundo envío, ni otra llamada, ni la base admiten otra', function () {
    $consulta = hcConsulta();

    hcFinalizar($consulta, hcDatos())->assertStatus(409);
    expect(GenerarPrestacion::alFinalizar($consulta->fresh(), null))->toBeNull()
        ->and(Prestacion::where('consulta_id', $consulta->id)->count())->toBe(1);

    $duplicada = tarifasPrestacionesDe($consulta)->replicate();
    expect(fn () => $duplicada->save())->toThrow(QueryException::class);
});

test('la adenda y la corrección por desbloqueo no crean otra prestación', function () {
    $consulta = hcConsulta();

    $this->post(route('admin.correcciones.adenda.store', $consulta), ['motivo' => 'Aclaración de prueba.', 'texto' => 'Texto de la adenda de prueba.'])
        ->assertSessionHasNoErrors();
    $this->actingAs(User::factory()->administrador()->create())
        ->post(route('admin.correcciones.desbloqueo.store', $consulta), ['motivo' => 'Se cargó mal el diagnóstico, error de tipeo.'])->assertSessionHasNoErrors();
    $desbloqueo = \App\Models\DesbloqueoConsulta::where('consulta_id', $consulta->id)->sole();
    $datos = hcFilasGuardadas($consulta);
    $datos['examen'] = ['hallazgos' => 'Hallazgo corregido de prueba.'];
    $this->post(route('admin.correcciones.corregir.guardar', $desbloqueo), $datos)->assertSessionHasNoErrors();

    expect($consulta->adendas()->count())->toBe(1)->and($consulta->versiones()->count())->toBe(1)
        ->and(Prestacion::where('consulta_id', $consulta->id)->count())->toBe(1);
});

test('cambiar la tarifa cierra la vigente y abre otra; las prestaciones ya tarifadas no cambian', function () {
    $vieja = tarifasPrestacionesTarifa(100000);
    $prestacion = tarifasPrestacionesDe(hcConsulta());

    $this->actingAs($this->gerente)->post(route('admin.tarifas.guardar-cambio', $vieja), ['monto' => '180.000', 'vigente_desde' => '06/10/2026'])
        ->assertSessionHasNoErrors();

    $nueva = Tarifa::where('reemplaza_a_id', $vieja->id)->sole();
    $vieja->refresh();
    expect($vieja->tieneEstado(Estado::HISTORICO))->toBeTrue()->and($vieja->vigente_hasta->format('Y-m-d'))->toBe('2026-10-05')
        ->and((int) $vieja->monto)->toBe(100000)
        ->and($nueva->vigente())->toBeTrue()->and((int) $nueva->monto)->toBe(180000)->and($nueva->vigente_hasta)->toBeNull()
        ->and((int) $prestacion->fresh()->monto)->toBe(100000)->and($prestacion->fresh()->tarifa_id)->toBe($vieja->id);

    // La que ya se cerró no se vuelve a cambiar; antes de hoy no se puede empezar.
    $this->post(route('admin.tarifas.guardar-cambio', $vieja), ['monto' => '190000', 'vigente_desde' => '06/10/2026'])->assertSessionHas('error', CambiarTarifa::YA_NO_VIGENTE);
    $this->post(route('admin.tarifas.guardar-cambio', $nueva), ['monto' => '190000', 'vigente_desde' => '05/10/2026'])->assertSessionHas('error');
    expect(Tarifa::count())->toBe(2);

    // El historial muestra las dos.
    $this->get(route('admin.tarifas.show', $nueva))->assertOk()->assertSee('₲ 180.000')->assertSee('₲ 100.000')->assertSee('Del 01/10/2026 al 05/10/2026');
});

test('el alta valida el monto y no admite una segunda tarifa para el mismo procedimiento y profesional', function () {
    $this->actingAs($this->gerente);
    $datos = ['procedimiento_id' => $this->consultaProc->id, 'profesional_id' => '', 'vigente_desde' => '06/10/2026'];

    foreach (['0', '-5', '150,50', 'abc', ''] as $malo) {
        $this->post(route('admin.tarifas.store'), [...$datos, 'monto' => $malo])->assertSessionHasErrors('monto');
    }
    $this->post(route('admin.tarifas.store'), [...$datos, 'monto' => '150.000'])->assertSessionHasNoErrors();
    $this->post(route('admin.tarifas.store'), [...$datos, 'monto' => '160000'])->assertSessionHas('error', AltaTarifa::YA_EXISTE);
    $this->post(route('admin.tarifas.store'), [...$datos, 'profesional_id' => $this->profesional->id, 'monto' => '160000'])->assertSessionHasNoErrors();

    expect(Tarifa::count())->toBe(2)->and((int) Tarifa::whereNull('profesional_id')->sole()->monto)->toBe(150000);
});

test('una tarifa y una prestación no se editan ni se borran desde el modelo', function () {
    $tarifa = tarifasPrestacionesTarifa(100000);
    $prestacion = tarifasPrestacionesDe(hcConsulta());

    expect(fn () => $tarifa->fresh()->forceFill(['monto' => 1])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $tarifa->fresh()->delete())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $prestacion->fresh()->forceFill(['monto' => 1])->save())->toThrow(RegistroEconomicoInmutable::class)
        ->and(fn () => $prestacion->fresh()->delete())->toThrow(RegistroEconomicoInmutable::class);
});

test('tarifar una pendiente usa la tarifa vigente el día del servicio y el monto queda fijado', function () {
    $prestacion = tarifasPrestacionesDe(hcConsulta()); // 06/10/2026, sin tarifa
    $this->actingAs($this->gerente);

    $this->post(route('admin.prestaciones.tarifar', $prestacion))->assertSessionHas('error');
    expect($prestacion->fresh()->pendiente())->toBeTrue();

    $vieja = tarifasPrestacionesTarifa(100000, desde: '2026-10-01');
    CambiarTarifa::ejecutar($this->gerente, $vieja, 200000, Carbon::parse('2026-10-08')); // la nueva todavía no rige el 06/10

    $this->get(route('admin.prestaciones.index'))->assertOk()->assertSee('Tarifar');
    $this->post(route('admin.prestaciones.tarifar', $prestacion))->assertSessionHasNoErrors()->assertSessionHas('status');

    $prestacion->refresh();
    expect($prestacion->tieneEstado(Estado::TARIFADO))->toBeTrue()->and((int) $prestacion->monto)->toBe(100000)
        ->and($prestacion->tarifa_id)->toBe($vieja->id)->and($prestacion->tarifada_por)->toBe($this->gerente->id);

    // Un segundo intento no cambia nada.
    $this->post(route('admin.prestaciones.tarifar', $prestacion))->assertSessionHas('error');
    expect((int) $prestacion->fresh()->monto)->toBe(100000);
});

test('anular exige motivo, conserva la prestación y solo lo pueden Administrador y Gerencia', function () {
    $prestacion = tarifasPrestacionesDe(hcConsulta());

    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::CAJA, PerfilesPredefinidos::FACTURACION, PerfilesPredefinidos::MEDICO] as $codigo) {
        $this->actingAs(tarifasPrestacionesUsuario($codigo))->post(route('admin.prestaciones.anular', $prestacion), ['motivo' => 'Motivo de prueba'])->assertForbidden();
    }

    $this->actingAs($this->gerente)->post(route('admin.prestaciones.anular', $prestacion), ['motivo' => ''])->assertSessionHasErrors('motivo');
    $this->post(route('admin.prestaciones.anular', $prestacion), ['motivo' => 'Cargada por error en la prueba.'])->assertSessionHasNoErrors();

    $prestacion->refresh();
    expect($prestacion->anulada())->toBeTrue()->and($prestacion->motivo_anulacion)->toBe('Cargada por error en la prueba.')
        ->and($prestacion->anulada_por)->toBe($this->gerente->id)->and(Prestacion::count())->toBe(1);
    $this->post(route('admin.prestaciones.anular', $prestacion), ['motivo' => 'Otra vez de prueba.'])->assertSessionHas('error');

    $this->actingAs(User::factory()->administrador()->create());
    $otra = tarifasPrestacionesDe(tarifasPrestacionesConsultaDeOtroMedico());
    $this->post(route('admin.prestaciones.anular', $otra), ['motivo' => 'Anulada por el Administrador.'])->assertSessionHasNoErrors();
    expect($otra->fresh()->anulada())->toBeTrue();
});

/** Una consulta finalizada por el Dr. Insfrán (para tener una segunda prestación). */
function tarifasPrestacionesConsultaDeOtroMedico(): Consulta
{
    $antes = auth()->user();
    test()->actingAs(test()->otroMedico);
    $consulta = hcConsulta();
    test()->actingAs($antes);

    return $consulta;
}

test('Médico y Enfermería no ven tarifas ni prestaciones; Recepción, Caja y Facturación solo las ven', function () {
    tarifasPrestacionesTarifa(123456);
    $prestacion = tarifasPrestacionesDe(hcConsulta());
    $tarifa = Tarifa::sole();

    // El médico que finaliza no ve ningún monto.
    expect($this->get(route('admin.consultas.show', $prestacion->consulta_id))->getContent())->not->toContain('123.456');

    foreach ([PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA] as $codigo) {
        $this->actingAs(tarifasPrestacionesUsuario($codigo));
        foreach ([route('admin.tarifas.index'), route('admin.tarifas.show', $tarifa), route('admin.prestaciones.index'), route('admin.prestaciones.show', $prestacion)] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/dashboard')->assertOk()->assertDontSee(route('admin.tarifas.index'))->assertDontSee(route('admin.prestaciones.index'));
    }

    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::CAJA, PerfilesPredefinidos::FACTURACION] as $codigo) {
        $this->actingAs(tarifasPrestacionesUsuario($codigo));
        $this->get(route('admin.tarifas.index'))->assertOk()->assertSee('₲ 123.456')->assertDontSee('Nueva tarifa')->assertDontSee(route('admin.tarifas.cambiar', $tarifa));
        $this->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertSee('₲ 123.456')->assertDontSee('Anular la prestación');
        $this->get(route('admin.tarifas.create'))->assertForbidden();
        $this->post(route('admin.tarifas.guardar-cambio', $tarifa), ['monto' => '1', 'vigente_desde' => '06/10/2026'])->assertForbidden();
        $this->post(route('admin.prestaciones.tarifar', $prestacion))->assertForbidden();
    }

    $this->actingAs($this->gerente);
    $this->get(route('admin.tarifas.index'))->assertOk()->assertSee('Nueva tarifa')->assertSee(route('admin.tarifas.cambiar', $tarifa));
    $this->get(route('admin.prestaciones.show', $prestacion))->assertOk()->assertSee('Anular la prestación');
});

test('el listado de prestaciones filtra por fecha, profesional y estado', function () {
    $propia = tarifasPrestacionesDe(hcConsulta()); // pendiente, Dra. Benítez
    $otra = tarifasPrestacionesDe(tarifasPrestacionesConsultaDeOtroMedico());
    $this->actingAs($this->gerente);

    $ver = fn (array $filtros) => $this->get(route('admin.prestaciones.index', $filtros))->assertOk()->getContent();
    expect($ver(['profesional' => $this->profesional->id]))->toContain(route('admin.prestaciones.show', $propia))->not->toContain(route('admin.prestaciones.show', $otra))
        ->and($ver(['desde' => '07/10/2026']))->not->toContain(route('admin.prestaciones.show', $propia))
        ->and($ver(['desde' => '06/10/2026', 'hasta' => '06/10/2026', 'estado' => 'PENDIENTE']))->toContain(route('admin.prestaciones.show', $propia))
        ->and($ver(['estado' => 'TARIFADO']))->not->toContain(route('admin.prestaciones.show', $propia));
});

test('auditoría del alta, el cambio, la prestación, el tarifado y la anulación; sin el módulo no se ven los montos', function () {
    $this->actingAs($this->gerente);
    $this->post(route('admin.tarifas.store'), ['procedimiento_id' => $this->eco->id, 'profesional_id' => '', 'monto' => '50000', 'vigente_desde' => '06/10/2026'])->assertSessionHasNoErrors();
    $vieja = Tarifa::sole();
    $this->post(route('admin.tarifas.guardar-cambio', $vieja), ['monto' => '60000', 'vigente_desde' => '07/10/2026'])->assertSessionHasNoErrors();
    $nueva = Tarifa::where('reemplaza_a_id', $vieja->id)->sole();

    $this->actingAs($this->medico);
    $prestacion = tarifasPrestacionesDe(hcConsulta()); // CONS-001 sin tarifa: pendiente
    tarifasPrestacionesTarifa(70000);
    $this->actingAs($this->gerente)->post(route('admin.prestaciones.tarifar', $prestacion))->assertSessionHasNoErrors();
    $this->post(route('admin.prestaciones.anular', $prestacion), ['motivo' => 'Motivo de prueba de la anulación.'])->assertSessionHasNoErrors();

    $log = fn (string $tabla, int $id, AccionAuditoria $accion) => LogAuditoria::where('tabla_afectada', $tabla)->where('registro_afectado_id', (string) $id)->where('accion', $accion->value)->get();
    expect($log('tarifas', $vieja->id, AccionAuditoria::CREAR))->toHaveCount(1)
        ->and($log('tarifas', $vieja->id, AccionAuditoria::EDITAR)->sole()->detalle)->toBe('Cambio de tarifa')
        ->and($log('tarifas', $nueva->id, AccionAuditoria::CREAR)->sole()->detalle)->toBe('Cambio de tarifa')
        ->and($log('prestaciones', $prestacion->id, AccionAuditoria::CREAR))->toHaveCount(1)
        ->and($log('prestaciones', $prestacion->id, AccionAuditoria::EDITAR)->sole()->detalle)->toBe('Tarifado')
        ->and($log('prestaciones', $prestacion->id, AccionAuditoria::ANULAR)->sole()->detalle)->toBe('Motivo: Motivo de prueba de la anulación.');

    // El Auditor ve el evento, pero no los montos (no tiene TARIFAS ni PRESTACIONES).
    $evento = $log('tarifas', $vieja->id, AccionAuditoria::CREAR)->sole();
    $this->actingAs(tarifasPrestacionesUsuario(PerfilesPredefinidos::AUDITOR))->get(route('admin.auditoria.show', $evento))->assertOk()
        ->assertSee('Contenido económico: se requiere permiso de lectura sobre Tarifas')->assertDontSee('50000');
    $this->actingAs($this->gerente)->get(route('admin.auditoria.show', $evento))->assertOk()->assertDontSee('Contenido económico')->assertSee('50000');
});
