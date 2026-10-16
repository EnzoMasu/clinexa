<?php

/*
 * La migración del catálogo de dietas corre también contra un catálogo SIN sus datos: sin el módulo DIETAS ni los estados
 * ACTIVO e INACTIVO, sin módulos en absoluto (instalación nueva) y en la simulación (`migrate --pretend`). Es idempotente,
 * reparte el permiso solo a Supervisión médica (y al Administrador) y no carga ninguna dieta.
 */

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function migracionDietasArchivo(): object
{
    return require base_path('database/migrations/2026_10_21_100001_create_dietas.php');
}

/** Olvida los ids de estados que Estado guarda en memoria (si no, devolvería ids de filas ya borradas). */
function migracionDietasOlvidarIds(): void
{
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
}

afterEach(fn () => migracionDietasOlvidarIds());

function migracionDietasAcciones(string $codigoPerfil): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, 'DIETAS:'))
        ->map(fn (string $clave) => substr($clave, strlen('DIETAS:')))->sort()->values()->all();
}

/** Base anterior a esta fase: sin la tabla, sin el módulo y sin nada de lo que cuelga de él. */
function migracionDietasSinNada(): void
{
    migracionDietasArchivo()->down();
    $modulo = ModuloSistema::where('codigo', 'DIETAS')->first();
    if ($modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
}

test('en una base sin el módulo: crea la tabla vacía, el módulo y reparte el permiso', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionDietasSinNada();
    expect(Schema::hasTable('dietas'))->toBeFalse()->and(ModuloSistema::where('codigo', 'DIETAS')->exists())->toBeFalse();

    migracionDietasArchivo()->up();

    expect(Schema::hasTable('dietas'))->toBeTrue()
        ->and(DB::table('dietas')->count())->toBe(0)
        ->and(ModuloSistema::where('codigo', 'DIETAS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ACTIVO', 'INACTIVO'])
        ->and(migracionDietasAcciones(PerfilesPredefinidos::SUPERVISION_MEDICA))->toBe(['CREAR', 'DESACTIVAR', 'EDITAR', 'VER'])
        ->and(migracionDietasAcciones(PerfilesPredefinidos::MEDICO))->toBe([])
        ->and(migracionDietasAcciones(PerfilesPredefinidos::RECEPCION))->toBe([])
        ->and(migracionDietasAcciones(PerfilesPredefinidos::ENFERMERIA))->toBe([])
        ->and(migracionDietasAcciones(PerfilAcceso::ADMINISTRADOR))->not->toBe([]);
});

test('en un catálogo sin el estado INACTIVO lo crea y asocia los dos al módulo', function () {
    // ACTIVO no puede faltar si hay módulos (todos lo usan); INACTIVO sí: solo lo referencia estado_modulo.
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionDietasSinNada();
    $inactivo = Estado::where('codigo', Estado::INACTIVO)->pluck('id');
    DB::table('estado_modulo')->whereIn('estado_id', $inactivo)->delete();
    DB::table('estados')->whereIn('id', $inactivo)->delete();
    migracionDietasOlvidarIds();

    migracionDietasArchivo()->up();

    expect(Estado::where('codigo', Estado::INACTIVO)->count())->toBe(1)
        ->and(ModuloSistema::where('codigo', 'DIETAS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ACTIVO', 'INACTIVO']);
});

test('en una instalación nueva (sin módulos) crea la tabla y no toca el catálogo', function () {
    migracionDietasArchivo()->down();
    expect(ModuloSistema::count())->toBe(0);

    migracionDietasArchivo()->up();

    expect(Schema::hasTable('dietas'))->toBeTrue()->and(ModuloSistema::count())->toBe(0);
});

test('con el catálogo ya completo no duplica estados, módulos ni permisos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionDietasArchivo()->down();
    [$estados, $modulos, $permisos] = [Estado::count(), ModuloSistema::count(), Permiso::count()];

    migracionDietasArchivo()->up();

    expect([Estado::count(), ModuloSistema::count(), Permiso::count()])->toBe([$estados, $modulos, $permisos]);
});

test('en la simulación (migrate --pretend), que no devuelve filas, no falla', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionDietasSinNada();

    expect(DB::pretend(fn () => migracionDietasArchivo()->up()))->not->toBeEmpty();
});
