<?php

/*
 * La migración de la orden de estudio corre también contra un catálogo SIN sus datos: sin los estados, sin los módulos
 * ESTUDIOS y ORDENES_ESTUDIO, sin módulos en absoluto (instalación nueva) y en la simulación (`migrate --pretend`, que no
 * devuelve filas). Es idempotente, reparte los permisos solo a Médico y Supervisión médica y no carga ningún estudio.
 */

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MIGRACION_ORDEN_ESTUDIO_MODULOS = ['ESTUDIOS', 'ORDENES_ESTUDIO'];

function migracionOrdenEstudioArchivo(): object
{
    return require base_path('database/migrations/2026_10_23_100001_create_ordenes_estudio.php');
}

/** Olvida los ids de estados que Estado guarda en memoria (si no, devolvería ids de filas ya borradas). */
function migracionOrdenEstudioOlvidarIds(): void
{
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
}

afterEach(fn () => migracionOrdenEstudioOlvidarIds());

/** Acciones de ese módulo que tiene el perfil de ese código. */
function migracionOrdenEstudioAcciones(string $codigoPerfil, string $modulo): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, "{$modulo}:"))
        ->map(fn (string $clave) => substr($clave, strlen("{$modulo}:")))->sort()->values()->all();
}

/** Base anterior a esta fase: sin las tablas, sin los módulos y sin nada de lo que cuelga de ellos. */
function migracionOrdenEstudioSinNada(): void
{
    migracionOrdenEstudioArchivo()->down();
    foreach (ModuloSistema::whereIn('codigo', MIGRACION_ORDEN_ESTUDIO_MODULOS)->get() as $modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
}

test('en una base sin los módulos ni los estados EMITIDO y ANULADO: los crea, arma las tablas y reparte los permisos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionOrdenEstudioSinNada();
    $estados = Estado::whereIn('codigo', [Estado::EMITIDO, Estado::ANULADO])->pluck('id');
    DB::table('estado_modulo')->whereIn('estado_id', $estados)->delete();
    DB::table('estados')->whereIn('id', $estados)->delete();
    migracionOrdenEstudioOlvidarIds();
    expect(Schema::hasTable('ordenes_estudio'))->toBeFalse()->and(ModuloSistema::whereIn('codigo', MIGRACION_ORDEN_ESTUDIO_MODULOS)->exists())->toBeFalse();

    migracionOrdenEstudioArchivo()->up();

    expect(Schema::hasTable('ordenes_estudio'))->toBeTrue()->and(Schema::hasTable('estudios'))->toBeTrue()
        ->and(DB::table('estudios')->count())->toBe(0)
        ->and(Estado::whereIn('codigo', [Estado::EMITIDO, Estado::ANULADO])->count())->toBe(2)
        ->and(ModuloSistema::where('codigo', 'ORDENES_ESTUDIO')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ANULADO', 'EMITIDO'])
        ->and(ModuloSistema::where('codigo', 'ESTUDIOS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ACTIVO', 'INACTIVO'])
        ->and(migracionOrdenEstudioAcciones(PerfilesPredefinidos::MEDICO, 'ORDENES_ESTUDIO'))->toBe(['CREAR', 'EDITAR', 'VER'])
        ->and(migracionOrdenEstudioAcciones(PerfilesPredefinidos::MEDICO, 'ESTUDIOS'))->toBe(['VER'])
        ->and(migracionOrdenEstudioAcciones(PerfilesPredefinidos::SUPERVISION_MEDICA, 'ORDENES_ESTUDIO'))->toBe(['VER'])
        ->and(migracionOrdenEstudioAcciones(PerfilesPredefinidos::SUPERVISION_MEDICA, 'ESTUDIOS'))->toBe(['CREAR', 'DESACTIVAR', 'EDITAR', 'VER'])
        ->and(migracionOrdenEstudioAcciones(PerfilAcceso::ADMINISTRADOR, 'ORDENES_ESTUDIO'))->not->toBe([]);
    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::ENFERMERIA] as $perfil) {
        foreach (MIGRACION_ORDEN_ESTUDIO_MODULOS as $modulo) {
            expect(migracionOrdenEstudioAcciones($perfil, $modulo))->toBe([]);
        }
    }
});

test('en una instalación nueva (sin módulos) crea las tablas y no toca el catálogo', function () {
    migracionOrdenEstudioArchivo()->down();
    expect(ModuloSistema::count())->toBe(0);

    migracionOrdenEstudioArchivo()->up();

    expect(Schema::hasTable('ordenes_estudio'))->toBeTrue()->and(ModuloSistema::count())->toBe(0);
});

test('con el catálogo ya completo no duplica estados, módulos ni permisos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionOrdenEstudioArchivo()->down();
    [$estados, $modulos, $permisos] = [Estado::count(), ModuloSistema::count(), Permiso::count()];

    migracionOrdenEstudioArchivo()->up();

    expect([Estado::count(), ModuloSistema::count(), Permiso::count()])->toBe([$estados, $modulos, $permisos]);
});

test('en la simulación (migrate --pretend), que no devuelve filas, no falla', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionOrdenEstudioSinNada();

    $consultas = DB::pretend(fn () => migracionOrdenEstudioArchivo()->up());

    expect($consultas)->not->toBeEmpty();
});
