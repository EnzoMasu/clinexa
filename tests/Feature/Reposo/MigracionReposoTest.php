<?php

/*
 * La migración del reposo médico corre también contra un catálogo SIN sus datos: sin los estados EMITIDO y ANULADO, sin
 * el módulo REPOSO_MEDICO, sin módulos en absoluto (instalación nueva) y en la simulación (`migrate --pretend`, que no
 * devuelve filas). Es idempotente en el catálogo y reparte el permiso solo a Médico y Supervisión médica.
 */

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\Permiso;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function migracionReposoArchivo(): object
{
    return require base_path('database/migrations/2026_10_20_100001_create_reposos_medicos.php');
}

/** Olvida los ids de estados que Estado guarda en memoria (si no, devolvería ids de filas ya borradas). */
function migracionReposoOlvidarIds(): void
{
    $propiedad = new ReflectionProperty(Estado::class, 'ids');
    $propiedad->setValue(null, []);
}

afterEach(fn () => migracionReposoOlvidarIds());

/** Acciones de REPOSO_MEDICO que tiene el perfil de ese código. */
function migracionReposoAcciones(string $codigoPerfil): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, 'REPOSO_MEDICO:'))
        ->map(fn (string $clave) => substr($clave, strlen('REPOSO_MEDICO:')))->sort()->values()->all();
}

/** Base anterior a esta fase: sin la tabla, sin el módulo y sin nada de lo que cuelga de él. */
function migracionReposoSinNada(): void
{
    migracionReposoArchivo()->down();
    $modulo = ModuloSistema::where('codigo', 'REPOSO_MEDICO')->first();
    if ($modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
}

test('en una base sin el módulo ni los estados EMITIDO y ANULADO: los crea, arma la tabla y reparte el permiso', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionReposoSinNada();
    $estados = Estado::whereIn('codigo', [Estado::EMITIDO, Estado::ANULADO])->pluck('id');
    DB::table('estado_modulo')->whereIn('estado_id', $estados)->delete();
    DB::table('estados')->whereIn('id', $estados)->delete();
    migracionReposoOlvidarIds();
    expect(Schema::hasTable('reposos_medicos'))->toBeFalse()->and(ModuloSistema::where('codigo', 'REPOSO_MEDICO')->exists())->toBeFalse();

    migracionReposoArchivo()->up();

    expect(Schema::hasTable('reposos_medicos'))->toBeTrue()
        ->and(Estado::whereIn('codigo', [Estado::EMITIDO, Estado::ANULADO])->count())->toBe(2)
        ->and(ModuloSistema::where('codigo', 'REPOSO_MEDICO')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ANULADO', 'EMITIDO'])
        ->and(migracionReposoAcciones(PerfilesPredefinidos::MEDICO))->toBe(['CREAR', 'EDITAR', 'VER'])
        ->and(migracionReposoAcciones(PerfilesPredefinidos::SUPERVISION_MEDICA))->toBe(['VER'])
        ->and(migracionReposoAcciones(PerfilesPredefinidos::RECEPCION))->toBe([])
        ->and(migracionReposoAcciones(PerfilesPredefinidos::ENFERMERIA))->toBe([])
        ->and(migracionReposoAcciones(PerfilAcceso::ADMINISTRADOR))->not->toBe([]);
});

test('en una instalación nueva (sin módulos) crea la tabla y no toca el catálogo', function () {
    migracionReposoArchivo()->down();
    expect(ModuloSistema::count())->toBe(0);

    migracionReposoArchivo()->up();

    expect(Schema::hasTable('reposos_medicos'))->toBeTrue()->and(ModuloSistema::count())->toBe(0);
});

test('con el catálogo ya completo no duplica estados, módulos ni permisos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionReposoArchivo()->down();
    [$estados, $modulos, $permisos] = [Estado::count(), ModuloSistema::count(), Permiso::count()];

    migracionReposoArchivo()->up();

    expect([Estado::count(), ModuloSistema::count(), Permiso::count()])->toBe([$estados, $modulos, $permisos]);
});

test('en la simulación (migrate --pretend), que no devuelve filas, no falla', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionReposoSinNada();

    $consultas = DB::pretend(fn () => migracionReposoArchivo()->up());

    expect($consultas)->not->toBeEmpty();
});
