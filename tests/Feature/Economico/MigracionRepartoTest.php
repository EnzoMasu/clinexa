<?php

/*
 * La migración del reparto corre también contra un catálogo SIN sus datos: sin el estado REPARTIDO, sin los módulos nuevos, sin
 * módulos en absoluto (instalación nueva) y en la simulación (`migrate --pretend`, que no devuelve filas). Es idempotente, da
 * los permisos solo a Gerencia, no carga acuerdos, equipos ni tipos y no reparte las prestaciones que ya existían.
 */

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Support\PerfilesPredefinidos;
use App\Support\Repartos\RepartirPrestacion;
use App\Support\Tarifas\AltaTarifa;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MIGRACION_REPARTO_MODULOS = ['ACUERDOS_REPARTO', 'REPARTOS', 'EQUIPOS', 'TIPOS_EQUIPO'];

const MIGRACION_REPARTO_TABLAS = ['tipos_equipo', 'equipos', 'equipo_procedimiento', 'acuerdos_reparto', 'repartos_prestacion', 'lineas_reparto'];

function migracionRepartoArchivo(): object
{
    return require base_path('database/migrations/2026_10_26_100001_create_reparto_economico.php');
}

/** Olvida los ids de estados que Estado guarda en memoria (si no, devolvería ids de filas ya borradas). */
function migracionRepartoOlvidarIds(): void
{
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
}

afterEach(function () {
    migracionRepartoOlvidarIds();
    Carbon::setTestNow();
});

function migracionRepartoAcciones(string $codigoPerfil, string $modulo): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, "{$modulo}:"))
        ->map(fn (string $clave) => substr($clave, strlen("{$modulo}:")))->sort()->values()->all();
}

/** Base anterior a esta fase: sin las tablas, sin los módulos ni nada de lo que cuelga de ellos. */
function migracionRepartoSinNada(): void
{
    migracionRepartoArchivo()->down();
    foreach (ModuloSistema::whereIn('codigo', MIGRACION_REPARTO_MODULOS)->get() as $modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
}

test('en una base sin los módulos ni el estado REPARTIDO: los crea, arma las tablas vacías y da los permisos solo a Gerencia', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionRepartoSinNada();
    $repartido = Estado::where('codigo', Estado::REPARTIDO)->value('id');
    DB::table('estado_modulo')->where('estado_id', $repartido)->delete();
    DB::table('estados')->where('id', $repartido)->delete();
    migracionRepartoOlvidarIds();
    expect(Schema::hasTable('acuerdos_reparto'))->toBeFalse()->and(ModuloSistema::whereIn('codigo', MIGRACION_REPARTO_MODULOS)->exists())->toBeFalse();

    migracionRepartoArchivo()->up();

    foreach (MIGRACION_REPARTO_TABLAS as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue()->and(DB::table($tabla)->count())->toBe(0);
    }
    expect(Estado::where('codigo', Estado::REPARTIDO)->exists())->toBeTrue()
        ->and(DB::table('hitos_sistema')->where('codigo', RepartirPrestacion::HITO)->count())->toBe(1)
        ->and(ModuloSistema::where('codigo', 'ACUERDOS_REPARTO')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['HISTORICO', 'VIGENTE'])
        ->and(ModuloSistema::where('codigo', 'REPARTOS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ANULADO', 'PENDIENTE', 'REPARTIDO'])
        ->and(migracionRepartoAcciones(PerfilesPredefinidos::GERENCIA, 'ACUERDOS_REPARTO'))->toBe(['CREAR', 'EDITAR', 'VER'])
        ->and(migracionRepartoAcciones(PerfilesPredefinidos::GERENCIA, 'REPARTOS'))->toBe(['VER'])
        ->and(migracionRepartoAcciones(PerfilesPredefinidos::GERENCIA, 'EQUIPOS'))->toBe(['CREAR', 'DESACTIVAR', 'EDITAR', 'VER'])
        ->and(migracionRepartoAcciones(PerfilesPredefinidos::GERENCIA, 'TIPOS_EQUIPO'))->toBe(['CREAR', 'DESACTIVAR', 'EDITAR', 'VER'])
        ->and(migracionRepartoAcciones(PerfilAcceso::ADMINISTRADOR, 'ACUERDOS_REPARTO'))->not->toBe([]);
    $otros = array_diff(array_keys(PerfilesPredefinidos::NOMBRES), [PerfilAcceso::ADMINISTRADOR, PerfilesPredefinidos::GERENCIA]);
    expect($otros)->not->toBeEmpty();
    foreach ($otros as $perfil) {
        foreach (MIGRACION_REPARTO_MODULOS as $modulo) {
            expect(migracionRepartoAcciones($perfil, $modulo))->toBe([]);
        }
    }
});

test('en una instalación nueva (sin módulos) crea las tablas y no toca el catálogo', function () {
    migracionRepartoArchivo()->down();
    expect(ModuloSistema::count())->toBe(0);

    migracionRepartoArchivo()->up();

    expect(Schema::hasTable('lineas_reparto'))->toBeTrue()->and(ModuloSistema::count())->toBe(0);
});

test('con el catálogo ya completo no duplica estados, módulos ni permisos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionRepartoArchivo()->down();
    [$estados, $modulos, $permisos] = [Estado::count(), ModuloSistema::count(), Permiso::count()];

    migracionRepartoArchivo()->up();

    expect([Estado::count(), ModuloSistema::count(), Permiso::count()])->toBe([$estados, $modulos, $permisos]);
});

test('no reparte las prestaciones que ya existían, ni las muestra como pendientes', function () {
    hcEscenario();
    PerfilesPredefinidos::asegurar();
    $procedimiento = \App\Models\Procedimiento::create(['codigo' => 'CONS-001', 'nombre' => 'Consulta', 'tipo' => 'CONSULTA', 'duracion_estimada_minutos' => 20]);
    AltaTarifa::ejecutar($this->medico, $procedimiento->id, null, 100000, Carbon::parse('2026-10-01'));
    DB::table('hitos_sistema')->where('codigo', 'PRESTACIONES')->update(['fecha_hora' => '2026-10-01 00:00:00']);
    $consulta = hcConsulta();
    expect(DB::table('prestaciones')->where('consulta_id', $consulta->id)->value('monto'))->not->toBeNull();
    migracionRepartoArchivo()->down();

    Carbon::setTestNow(Carbon::now()->addHour()); // la migración corre después
    migracionRepartoArchivo()->up();

    expect(DB::table('repartos_prestacion')->count())->toBe(0)->and(RepartirPrestacion::pendientes()->count())->toBe(0);
});

test('en la simulación (migrate --pretend), que no devuelve filas, no falla ni escribe', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionRepartoSinNada();

    $consultas = DB::pretend(fn () => migracionRepartoArchivo()->up());

    expect($consultas)->not->toBeEmpty()->and(Schema::hasTable('acuerdos_reparto'))->toBeFalse()
        ->and(ModuloSistema::whereIn('codigo', MIGRACION_REPARTO_MODULOS)->exists())->toBeFalse();
});
