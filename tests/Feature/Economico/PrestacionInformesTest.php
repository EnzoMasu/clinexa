<?php

/*
 * La prestación de cada informe de estudio (docs/tarifas-prestaciones.md): al finalizar, la base y una por informe vigente, con
 * tarifa y reparto (el equipo si el procedimiento usa uno); sin duplicar con la base del turno; retirados, adenda, desbloqueo e
 * informes anteriores no generan; la falla no frena el cierre y el informe aparece en "Consultas sin prestación" con "Generar";
 * origen en el listado; permisos y el médico sin montos. Datos, precios y porcentajes inventados. Prefijo "prestacionInformes".
 */

use App\Enums\AccionAuditoria;
use App\Models\AcuerdoReparto;
use App\Models\Consulta;
use App\Models\DesbloqueoConsulta;
use App\Models\Equipo;
use App\Models\InformeEstudio;
use App\Models\LineaReparto;
use App\Models\LogAuditoria;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Proveedor;
use App\Models\TipoEquipo;
use App\Models\Turno;
use App\Models\User;
use App\Support\PerfilesPredefinidos;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Repartos\AltaAcuerdo;
use App\Support\Repartos\Porcentaje;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    hcEscenario(); // "hoy": martes 06/10/2026, 09:00 en Paraguay
    PerfilesPredefinidos::asegurar();
    $this->consultaProc = Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    $this->eco = Procedimiento::create(['codigo' => 'ECO-PRUEBA', 'nombre' => 'Ecografía de prueba', 'tipo' => 'ESTUDIO', 'duracion_estimada_minutos' => 30]);
    $this->gerente = prestacionInformesUsuario(PerfilesPredefinidos::GERENCIA);
    // Las migraciones guardaron la hora real en que corrieron; el "hoy" de estos tests es anterior.
    DB::table('hitos_sistema')->whereIn('codigo', [GenerarPrestacion::HITO, GenerarPrestacion::HITO_INFORMES, RepartirPrestacion::HITO])
        ->update(['fecha_hora' => '2026-10-01 00:00:00']);
});

afterEach(fn () => Carbon::setTestNow());

function prestacionInformesUsuario(string $codigo): User
{
    return User::factory()->conPerfiles([PerfilAcceso::where('codigo', $codigo)->sole()])->create();
}

function prestacionInformesTarifa(Procedimiento $procedimiento, int $monto): void
{
    AltaTarifa::ejecutar(test()->gerente, $procedimiento->id, null, $monto, Carbon::parse('2026-10-01'));
}

/** Tarifas de la consulta y de la ecografía, acuerdo general de la Dra. Benítez (40 %) y el ecógrafo con el 20 % de su dueño. */
function prestacionInformesPrecios(): void
{
    prestacionInformesTarifa(test()->consultaProc, 100000);
    prestacionInformesTarifa(test()->eco, 300000);
    AltaAcuerdo::ejecutar(test()->gerente, AcuerdoReparto::PROFESIONAL, test()->profesional->id, null, null, Porcentaje::desdeTexto('40'), Carbon::parse('2026-10-01'));
    $proveedor = Proveedor::create(['persona_id' => Persona::factory()->create(['apellidos' => 'Equipos', 'nombres' => 'Dueño'])->id]);
    $equipo = Equipo::create(['nombre' => 'Ecógrafo de prueba', 'tipo_equipo_id' => TipoEquipo::create(['nombre' => 'Ecógrafo'])->id, 'proveedor_id' => $proveedor->id]);
    $equipo->procedimientos()->sync([test()->eco->id]);
    AltaAcuerdo::ejecutar(test()->gerente, AcuerdoReparto::EQUIPO, null, null, $equipo->id, Porcentaje::desdeTexto('20'), Carbon::parse('2026-10-01'));
}

/** Los informes, como los manda el formulario: uno por procedimiento indicado. */
function prestacionInformesDatos(Procedimiento ...$procedimientos): array
{
    return ['con_informes' => '1', 'informes' => collect($procedimientos)->values()->map(fn (Procedimiento $procedimiento, int $i) => [
        'uid' => "n-{$i}", 'id' => '', 'activo' => '1', 'procedimiento_id' => (string) $procedimiento->id, 'texto' => 'Informe inventado '.($i + 1).'.',
    ])->all()];
}

/** Atiende (sin turno o con el turno) y finaliza con esos informes. */
function prestacionInformesConsulta(array $informes, ?Turno $turno = null): Consulta
{
    hcGuardarNueva($informes, $turno)->assertSessionHasNoErrors();

    return Consulta::latest('id')->firstOrFail();
}

/** Las líneas del reparto: beneficiario => monto. */
function prestacionInformesLineas(Prestacion $prestacion): array
{
    return LineaReparto::whereHas('reparto', fn ($query) => $query->where('prestacion_id', $prestacion->id))->orderBy('id')->get()
        ->mapWithKeys(fn (LineaReparto $linea) => [$linea->beneficiario => (int) $linea->monto])->all();
}

test('consulta sin turno con una ecografía: dos prestaciones, la de la ecografía con su tarifa y tres líneas de reparto', function () {
    prestacionInformesPrecios();

    $consulta = prestacionInformesConsulta(prestacionInformesDatos($this->eco));

    $informe = InformeEstudio::sole();
    $prestaciones = Prestacion::where('consulta_id', $consulta->id)->orderBy('id')->get();
    expect($prestaciones)->toHaveCount(2)
        ->and($prestaciones[0]->informe_estudio_id)->toBeNull()->and($prestaciones[0]->procedimiento_id)->toBe($this->consultaProc->id)
        ->and((int) $prestaciones[0]->monto)->toBe(100000)
        ->and($prestaciones[1]->informe_estudio_id)->toBe($informe->id)->and($prestaciones[1]->procedimiento_id)->toBe($this->eco->id)
        ->and($prestaciones[1]->profesional_id)->toBe($this->profesional->id)->and($prestaciones[1]->fecha_servicio->format('Y-m-d'))->toBe('2026-10-06')
        ->and((int) $prestaciones[1]->monto)->toBe(300000)->and($prestaciones[1]->origenTexto())->toBe('Informe de estudio')
        ->and(prestacionInformesLineas($prestaciones[1]))->toBe(['CLINICA' => 120000, 'PROFESIONAL' => 120000, 'EQUIPO' => 60000])
        // La consulta no usa equipo: dos líneas.
        ->and(prestacionInformesLineas($prestaciones[0]))->toBe(['CLINICA' => 60000, 'PROFESIONAL' => 40000]);
    expect(LogAuditoria::where('tabla_afectada', 'prestaciones')->where('accion', AccionAuditoria::CREAR->value)->pluck('detalle')->all())
        ->toBe(["Al finalizar la consulta {$consulta->id}", "Al finalizar la consulta {$consulta->id} (informe de estudio {$informe->id})"]);
});

test('turno de ecografía con un informe de ecografía: una sola prestación; con dos informes iguales, dos', function () {
    prestacionInformesPrecios();

    $una = prestacionInformesConsulta(prestacionInformesDatos($this->eco), hcTurno(['procedimiento_id' => $this->eco->id]));
    expect(Prestacion::where('consulta_id', $una->id)->get()->map->only(['procedimiento_id', 'informe_estudio_id'])->all())
        ->toBe([['procedimiento_id' => $this->eco->id, 'informe_estudio_id' => null]]);

    $dos = prestacionInformesConsulta(prestacionInformesDatos($this->eco, $this->eco), hcTurno(['procedimiento_id' => $this->eco->id, 'hora_inicio' => '09:00', 'hora_fin' => '09:30']));
    $segundo = InformeEstudio::where('consulta_id', $dos->id)->orderBy('orden')->get()[1];
    expect(Prestacion::where('consulta_id', $dos->id)->orderBy('id')->pluck('informe_estudio_id')->all())->toBe([null, $segundo->id])
        ->and(GenerarPrestacion::informesSinPrestacion()->count())->toBe(0);

    // Sin turno, dos ecografías: la consulta y una por cada informe.
    $sinTurno = prestacionInformesConsulta(prestacionInformesDatos($this->eco, $this->eco));
    expect(Prestacion::where('consulta_id', $sinTurno->id)->count())->toBe(3);
});

test('un informe retirado antes de finalizar no cobra; uno retirado después no anula su prestación', function () {
    prestacionInformesPrecios();
    $consulta = hcEnCurso();
    hcAutoguardar($consulta, prestacionInformesDatos($this->eco, $this->eco))->assertOk();
    $filas = InformeEstudio::orderBy('orden')->get()->map(fn ($informe) => ['id' => (string) $informe->id, 'activo' => '1',
        'procedimiento_id' => (string) $informe->procedimiento_id, 'texto' => $informe->texto])->all();
    $filas[1]['activo'] = '0';

    hcFinalizar($consulta, hcDatos(['con_informes' => '1', 'informes' => $filas]))->assertSessionHasNoErrors();

    [$vigente, $retirado] = InformeEstudio::orderBy('orden')->get()->all();
    expect(Prestacion::where('consulta_id', $consulta->id)->orderBy('id')->pluck('informe_estudio_id')->all())->toBe([null, $vigente->id])
        ->and(GenerarPrestacion::informesSinPrestacion()->count())->toBe(0);

    // Retirarlo por desbloqueo: la prestación sigue (la anula Gerencia con motivo).
    $this->actingAs(User::factory()->administrador()->create())
        ->post(route('admin.correcciones.desbloqueo.store', $consulta), ['motivo' => 'Retirar un informe cargado por error.'])->assertSessionHasNoErrors();
    $filas[0]['activo'] = '0';
    $this->post(route('admin.correcciones.corregir.guardar', DesbloqueoConsulta::sole()), [...hcFilasGuardadas($consulta), 'examen' => ['hallazgos' => $consulta->examenFisico?->hallazgos], 'con_informes' => '1', 'informes' => $filas])
        ->assertSessionHasNoErrors();
    expect($vigente->fresh()->activo)->toBeFalse()->and($retirado->fresh()->activo)->toBeFalse()
        ->and(Prestacion::where('informe_estudio_id', $vigente->id)->sole()->anulada())->toBeFalse();
});

test('sin tarifa de la ecografía su prestación queda pendiente de tarifar y sin reparto; la consulta se finaliza igual', function () {
    prestacionInformesTarifa($this->consultaProc, 100000);

    $consulta = prestacionInformesConsulta(prestacionInformesDatos($this->eco));

    $eco = Prestacion::whereNotNull('informe_estudio_id')->sole();
    expect($consulta->fresh()->finalizada())->toBeTrue()->and($eco->pendiente())->toBeTrue()->and($eco->monto)->toBeNull()
        ->and($eco->reparto)->toBeNull()
        ->and(Prestacion::whereNull('informe_estudio_id')->sole()->pendiente())->toBeFalse();
});

test('sin acuerdos la prestación del informe queda pendiente de reparto', function () {
    prestacionInformesTarifa($this->eco, 300000);

    prestacionInformesConsulta(prestacionInformesDatos($this->eco));

    $eco = Prestacion::whereNotNull('informe_estudio_id')->sole();
    expect((int) $eco->monto)->toBe(300000)->and(RepartirPrestacion::pendientes()->whereKey($eco->id)->exists())->toBeTrue();
});

test('la adenda y un informe agregado por desbloqueo no crean prestaciones ni quedan pendientes', function () {
    prestacionInformesPrecios();
    $consulta = prestacionInformesConsulta(prestacionInformesDatos($this->eco));
    expect(Prestacion::count())->toBe(2);

    $this->post(route('admin.correcciones.adenda.store', $consulta), ['motivo' => 'Aclaración de prueba.', 'texto' => 'Texto de la adenda de prueba.'])
        ->assertSessionHasNoErrors();
    Carbon::setTestNow(Carbon::now()->addHour());
    $this->actingAs(User::factory()->administrador()->create())
        ->post(route('admin.correcciones.desbloqueo.store', $consulta), ['motivo' => 'Falta un informe, error de carga.'])->assertSessionHasNoErrors();
    $filas = InformeEstudio::get()->map(fn ($informe) => ['id' => (string) $informe->id, 'activo' => '1', 'procedimiento_id' => (string) $informe->procedimiento_id, 'texto' => $informe->texto])->all();
    $filas[] = ['id' => '', 'activo' => '1', 'procedimiento_id' => (string) $this->eco->id, 'texto' => 'Informe agregado por corrección.'];
    $this->post(route('admin.correcciones.corregir.guardar', DesbloqueoConsulta::sole()), [...hcFilasGuardadas($consulta), 'examen' => ['hallazgos' => $consulta->examenFisico?->hallazgos], 'con_informes' => '1', 'informes' => $filas])
        ->assertSessionHasNoErrors();

    expect(InformeEstudio::count())->toBe(2)->and(Prestacion::count())->toBe(2)
        ->and(GenerarPrestacion::informesSinPrestacion()->count())->toBe(0)
        ->and(GenerarPrestacion::alFinalizar($consulta->fresh(), null))->toBeNull()
        ->and(Prestacion::count())->toBe(2);
});

test('los informes de consultas finalizadas antes de la migración, y los anteriores sin procedimiento, no generan', function () {
    $consulta = prestacionInformesConsulta(prestacionInformesDatos($this->eco)); // finalizada el 06/10/2026 a las 12:00 UTC
    DB::table('prestaciones')->whereNotNull('informe_estudio_id')->delete();
    DB::table('hitos_sistema')->where('codigo', GenerarPrestacion::HITO_INFORMES)->update(['fecha_hora' => '2026-10-06 13:00:00']);
    $this->actingAs($this->gerente);

    expect(GenerarPrestacion::informesSinPrestacion()->count())->toBe(0);
    $this->get(route('admin.prestaciones.index'))->assertOk()->assertDontSee('informe de estudio sin prestación')->assertDontSee('Consultas sin prestación');
    $this->post(route('admin.prestaciones.generar-informe', InformeEstudio::sole()))->assertSessionHas('error');
    expect(Prestacion::whereNotNull('informe_estudio_id')->count())->toBe(0);

    // Uno anterior (sin procedimiento) en una consulta finalizada después: tampoco.
    $this->actingAs($this->medico);
    DB::table('hitos_sistema')->where('codigo', GenerarPrestacion::HITO_INFORMES)->update(['fecha_hora' => '2026-10-01 00:00:00']);
    $otra = hcEnCurso();
    $otra->informesEstudio()->create(['estudio_nombre' => 'Estudio anterior inventado', 'texto' => 'Informe anterior.', 'orden' => 1]);
    hcFinalizar($otra, hcDatos(['con_informes' => '1', 'informes' => [['id' => (string) InformeEstudio::latest('id')->value('id'), 'activo' => '1', 'procedimiento_id' => '', 'texto' => 'Informe anterior.']]]))
        ->assertSessionHasNoErrors();
    expect(Prestacion::where('consulta_id', $otra->id)->pluck('informe_estudio_id')->all())->toBe([null]);
});

test('si la del informe falla, la consulta se finaliza igual, queda un FALLO sin contenido clínico y "Generar" no duplica', function () {
    prestacionInformesPrecios();
    Prestacion::creating(fn (Prestacion $prestacion) => $prestacion->informe_estudio_id ? throw new RuntimeException('Falla de prueba') : null);

    $consulta = prestacionInformesConsulta(prestacionInformesDatos($this->eco));

    $informe = InformeEstudio::sole();
    expect($consulta->fresh()->finalizada())->toBeTrue()->and(Prestacion::where('consulta_id', $consulta->id)->pluck('informe_estudio_id')->all())->toBe([null]);
    $evento = LogAuditoria::where('accion', AccionAuditoria::FALLO->value)->sole();
    expect($evento->tabla_afectada)->toBe('prestaciones')
        ->and($evento->valor_nuevo)->toBe(['consulta_id' => $consulta->id, 'informe_estudio_id' => $informe->id, 'profesional_id' => $this->profesional->id, 'fecha_servicio' => '2026-10-06'])
        ->and($evento->detalle)->toContain("informe de estudio {$informe->id}")->not->toContain('Informe inventado')->not->toContain('garganta')
        ->and(GenerarPrestacion::informesSinPrestacion()->pluck('id')->all())->toBe([$informe->id])
        ->and(GenerarPrestacion::sinPrestacion()->count())->toBe(0);

    Prestacion::flushEventListeners();
    $this->actingAs($this->gerente);
    $this->get(route('admin.prestaciones.index'))->assertOk()->assertSee('Hay 1 informe de estudio sin prestación.')->assertDontSee('consulta finalizada sin prestación')
        ->assertSee('Consultas sin prestación')->assertSee(route('admin.prestaciones.generar-informe', $informe))->assertSee('Ecografía de prueba');

    $this->post(route('admin.prestaciones.generar-informe', $informe))->assertSessionHasNoErrors()->assertSessionHas('status');
    $eco = Prestacion::where('informe_estudio_id', $informe->id)->sole();
    expect((int) $eco->monto)->toBe(300000)->and(prestacionInformesLineas($eco))->toBe(['CLINICA' => 120000, 'PROFESIONAL' => 120000, 'EQUIPO' => 60000]);

    $this->post(route('admin.prestaciones.generar-informe', $informe))->assertSessionHas('error');
    $this->post(route('admin.prestaciones.generar', $consulta))->assertSessionHas('error');
    expect(Prestacion::where('consulta_id', $consulta->id)->count())->toBe(2);
    $this->get(route('admin.prestaciones.index'))->assertOk()->assertDontSee('sin prestación.')->assertDontSee('Consultas sin prestación');
});

test('la base solo admite una por consulta y cada informe una sola', function () {
    prestacionInformesConsulta(prestacionInformesDatos($this->eco));
    $base = Prestacion::whereNull('informe_estudio_id')->sole();
    $deInforme = Prestacion::whereNotNull('informe_estudio_id')->sole();

    expect(fn () => $base->replicate()->save())->toThrow(QueryException::class)
        ->and(fn () => $deInforme->replicate()->save())->toThrow(QueryException::class)
        ->and(Prestacion::count())->toBe(2);
});

test('permisos: Recepción, Caja y Facturación ven la cantidad y las prestaciones; solo Administrador y Gerencia generan', function () {
    Prestacion::creating(fn (Prestacion $prestacion) => $prestacion->informe_estudio_id ? throw new RuntimeException('Falla de prueba') : null);
    prestacionInformesConsulta(prestacionInformesDatos($this->eco));
    Prestacion::flushEventListeners();
    $informe = InformeEstudio::sole();

    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::CAJA, PerfilesPredefinidos::FACTURACION] as $codigo) {
        $this->actingAs(prestacionInformesUsuario($codigo));
        $this->get(route('admin.prestaciones.index'))->assertOk()->assertSee('Hay 1 informe de estudio sin prestación.')
            ->assertDontSee(route('admin.prestaciones.generar-informe', $informe));
        $this->post(route('admin.prestaciones.generar-informe', $informe))->assertForbidden();
    }
    foreach ([PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA] as $codigo) {
        $this->actingAs(prestacionInformesUsuario($codigo))->post(route('admin.prestaciones.generar-informe', $informe))->assertForbidden();
    }
    expect(Prestacion::whereNotNull('informe_estudio_id')->count())->toBe(0);

    $this->actingAs(User::factory()->administrador()->create())->post(route('admin.prestaciones.generar-informe', $informe))->assertSessionHasNoErrors();
    expect(Prestacion::whereNotNull('informe_estudio_id')->count())->toBe(1);
});

test('el médico ve el nombre del procedimiento al escribir el informe, nunca montos', function () {
    prestacionInformesPrecios();
    $consulta = hcEnCurso();
    hcAutoguardar($consulta, prestacionInformesDatos($this->eco))->assertOk();

    $pantalla = $this->get(route('admin.consultas.atencion', $consulta))->assertOk()->assertSee('Ecograf')->getContent(); // el nombre va en el JSON de la pantalla
    expect(collect(['300.000', '300000', '₲'])->filter(fn ($monto) => str_contains($pantalla, $monto))->values()->all())->toBe([]);

    hcFinalizar($consulta, hcDatos(['con_informes' => '1', 'informes' => [['id' => (string) InformeEstudio::sole()->id, 'activo' => '1',
        'procedimiento_id' => (string) $this->eco->id, 'texto' => 'Informe inventado 1.']]]))->assertSessionHasNoErrors();
    $lectura = $this->get(route('admin.consultas.show', $consulta))->assertOk()->getContent();
    expect(collect(['300.000', '₲'])->filter(fn ($monto) => str_contains($lectura, $monto))->values()->all())->toBe([]);
    $this->get(route('admin.prestaciones.index'))->assertForbidden();
});

test('el listado muestra el origen y filtra por él; el detalle muestra las demás prestaciones de la consulta', function () {
    prestacionInformesPrecios();
    $consulta = prestacionInformesConsulta(prestacionInformesDatos($this->eco));
    $base = Prestacion::whereNull('informe_estudio_id')->sole();
    $eco = Prestacion::whereNotNull('informe_estudio_id')->sole();
    $this->actingAs(prestacionInformesUsuario(PerfilesPredefinidos::RECEPCION));

    $this->get(route('admin.prestaciones.index'))->assertOk()->assertSee('Origen')->assertSee('Informe de estudio')->assertSee('Consulta/Turno')
        ->assertSee(route('admin.prestaciones.show', $base))->assertSee(route('admin.prestaciones.show', $eco));
    $this->get(route('admin.prestaciones.index', ['origen' => 'INFORME']))->assertOk()
        ->assertSee(route('admin.prestaciones.show', $eco))->assertDontSee(route('admin.prestaciones.show', $base));
    $this->get(route('admin.prestaciones.index', ['origen' => 'CONSULTA']))->assertOk()
        ->assertSee(route('admin.prestaciones.show', $base))->assertDontSee(route('admin.prestaciones.show', $eco));

    $this->get(route('admin.prestaciones.show', $eco))->assertOk()->assertSee('Informe de estudio')
        ->assertSee("Otras prestaciones de la consulta N.º {$consulta->id}")->assertSee(route('admin.prestaciones.show', $base))
        ->assertDontSee('Informe inventado');
});

test('la migración: con prestaciones existentes las conserva, en simulación no cambia nada y no depende del catálogo', function () {
    $migracion = require base_path('database/migrations/2026_10_27_100001_prestacion_de_informes_de_estudio.php');
    hcConsulta(); // una prestación base existente
    $migracion->down();
    $antes = DB::table('prestaciones')->get()->map(fn ($fila) => (array) $fila)->all();
    // Catálogo sin sus datos: la migración no lee módulos, permisos, estados ni procedimientos.
    DB::table('estado_modulo')->delete();
    DB::table('modulos_sistema')->update(['codigo' => DB::raw("'X-' || id")]);

    $sentencias = DB::pretend(fn () => $migracion->up());
    expect($sentencias)->not->toBeEmpty()->and(Schema::hasColumn('prestaciones', 'informe_estudio_id'))->toBeFalse()
        ->and(DB::table('hitos_sistema')->where('codigo', GenerarPrestacion::HITO_INFORMES)->exists())->toBeFalse();

    $migracion->up();
    $indices = collect(DB::select("select name, sql from sqlite_master where type = 'index' and tbl_name = 'prestaciones'"))->pluck('sql', 'name');
    expect(DB::table('prestaciones')->get()->map(fn ($fila) => collect((array) $fila)->except('informe_estudio_id')->all())->all())->toBe($antes)
        ->and(Schema::hasColumn('informes_estudio', 'procedimiento_id'))->toBeTrue()
        ->and($indices['prestaciones_base_unica'])->toContain('WHERE informe_estudio_id IS NULL')
        ->and($indices['prestaciones_informe_unica'])->toContain('WHERE informe_estudio_id IS NOT NULL')
        ->and($indices->has('prestaciones_consulta_id_unique'))->toBeFalse()
        ->and(DB::table('hitos_sistema')->where('codigo', GenerarPrestacion::HITO_INFORMES)->count())->toBe(1);
});
