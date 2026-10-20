<?php

/*
 * La migración de tarifas y prestaciones corre también contra un catálogo SIN sus datos: sin los estados (VIGENTE, HISTORICO,
 * TARIFADO), sin los módulos TARIFAS y PRESTACIONES, sin módulos en absoluto (instalación nueva) y en la simulación
 * (`migrate --pretend`, que no devuelve filas). Es idempotente, reparte los permisos solo a Gerencia, Recepción, Caja y
 * Facturación, carga solo la moneda PYG (ninguna tarifa) y no genera prestaciones de consultas ya cerradas.
 */

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MIGRACION_TARIFAS_MODULOS = ['TARIFAS', 'PRESTACIONES'];

function migracionTarifasArchivo(): object
{
    return require base_path('database/migrations/2026_10_25_100001_create_tarifas_y_prestaciones.php');
}

/** Olvida los ids de estados que Estado guarda en memoria (si no, devolvería ids de filas ya borradas). */
function migracionTarifasOlvidarIds(): void
{
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
}

afterEach(function () {
    migracionTarifasOlvidarIds();
    Carbon::setTestNow();
});

function migracionTarifasAcciones(string $codigoPerfil, string $modulo): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, "{$modulo}:"))
        ->map(fn (string $clave) => substr($clave, strlen("{$modulo}:")))->sort()->values()->all();
}

/** Base anterior a esta fase: sin las tablas, sin los módulos ni nada de lo que cuelga de ellos. */
function migracionTarifasSinNada(): void
{
    migracionTarifasArchivo()->down();
    foreach (ModuloSistema::whereIn('codigo', MIGRACION_TARIFAS_MODULOS)->get() as $modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
}

test('en una base sin los módulos ni sus estados: los crea, arma las tablas, carga PYG y reparte los permisos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionTarifasSinNada();
    $estados = Estado::whereIn('codigo', [Estado::VIGENTE, Estado::HISTORICO, Estado::TARIFADO])->pluck('id');
    DB::table('estado_modulo')->whereIn('estado_id', $estados)->delete();
    DB::table('estados')->whereIn('id', $estados)->delete();
    migracionTarifasOlvidarIds();
    expect(Schema::hasTable('tarifas'))->toBeFalse()->and(ModuloSistema::whereIn('codigo', MIGRACION_TARIFAS_MODULOS)->exists())->toBeFalse();

    migracionTarifasArchivo()->up();

    expect(Schema::hasTable('tarifas'))->toBeTrue()->and(Schema::hasTable('prestaciones'))->toBeTrue()
        ->and(DB::table('tarifas')->count())->toBe(0)
        ->and(DB::table('monedas')->pluck('codigo')->all())->toBe(['PYG'])
        ->and(DB::table('hitos_sistema')->where('codigo', 'PRESTACIONES')->count())->toBe(1)
        ->and(Estado::whereIn('codigo', [Estado::VIGENTE, Estado::HISTORICO, Estado::TARIFADO])->count())->toBe(3)
        ->and(ModuloSistema::where('codigo', 'TARIFAS')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['HISTORICO', 'VIGENTE'])
        ->and(ModuloSistema::where('codigo', 'PRESTACIONES')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ANULADO', 'PENDIENTE', 'TARIFADO'])
        ->and(migracionTarifasAcciones(PerfilesPredefinidos::GERENCIA, 'TARIFAS'))->toBe(['CREAR', 'EDITAR', 'VER'])
        ->and(migracionTarifasAcciones(PerfilesPredefinidos::GERENCIA, 'PRESTACIONES'))->toBe(['DESACTIVAR', 'EDITAR', 'VER'])
        ->and(migracionTarifasAcciones(PerfilAcceso::ADMINISTRADOR, 'PRESTACIONES'))->not->toBe([]);
    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::CAJA, PerfilesPredefinidos::FACTURACION] as $perfil) {
        foreach (MIGRACION_TARIFAS_MODULOS as $modulo) {
            expect(migracionTarifasAcciones($perfil, $modulo))->toBe(['VER']);
        }
    }
    foreach ([PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA, PerfilesPredefinidos::SUPERVISION_MEDICA, PerfilesPredefinidos::AUDITOR] as $perfil) {
        foreach (MIGRACION_TARIFAS_MODULOS as $modulo) {
            expect(migracionTarifasAcciones($perfil, $modulo))->toBe([]);
        }
    }
});

test('en una instalación nueva (sin módulos) crea las tablas y no toca el catálogo', function () {
    migracionTarifasArchivo()->down();
    expect(ModuloSistema::count())->toBe(0);

    migracionTarifasArchivo()->up();

    expect(Schema::hasTable('prestaciones'))->toBeTrue()->and(ModuloSistema::count())->toBe(0)
        ->and(DB::table('monedas')->where('codigo', 'PYG')->exists())->toBeTrue();
});

test('con el catálogo ya completo no duplica estados, módulos ni permisos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionTarifasArchivo()->down();
    [$estados, $modulos, $permisos] = [Estado::count(), ModuloSistema::count(), Permiso::count()];

    migracionTarifasArchivo()->up();

    expect([Estado::count(), ModuloSistema::count(), Permiso::count()])->toBe([$estados, $modulos, $permisos]);
});

test('no genera prestaciones de las consultas que ya estaban cerradas', function () {
    hcEscenario();
    $consulta = hcConsulta();
    migracionTarifasArchivo()->down();

    migracionTarifasArchivo()->up();

    expect($consulta->fresh()->finalizada())->toBeTrue()->and(DB::table('prestaciones')->count())->toBe(0);
});

test('en la simulación (migrate --pretend), que no devuelve filas, no falla', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionTarifasSinNada();

    $consultas = DB::pretend(fn () => migracionTarifasArchivo()->up());

    expect($consultas)->not->toBeEmpty();
});
