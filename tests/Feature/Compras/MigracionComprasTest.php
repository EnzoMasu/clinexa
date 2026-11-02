<?php

/*
 * La migración de compras (docs/compras-gastos.md) en una base existente: crea los tres módulos con sus estados (PENDIENTE inicial en
 * COMPRAS) y da a los perfiles predefinidos existentes los permisos de la matriz; es idempotente y en una instalación nueva (sin
 * módulos) deja los módulos al seeder. Datos inventados. Prefijo "migracionCompras".
 */

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MIGRACION_COMPRAS_MODULOS = ['COMPRAS', 'CONFIRMACION_COMPRAS', 'TIPOS_COMPROBANTE_COMPRA'];

function migracionComprasArchivo(): object
{
    return require base_path('database/migrations/2026_11_02_100003_create_compras.php');
}

function migracionComprasAcciones(string $codigoPerfil, string $modulo): array
{
    return collect(PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos())
        ->filter(fn (string $clave) => str_starts_with($clave, "{$modulo}:"))
        ->map(fn (string $clave) => substr($clave, strlen("{$modulo}:")))->sort()->values()->all();
}

/** Base anterior a esta fase: sin la tabla de compras ni los tres módulos y sus permisos. */
function migracionComprasSinNada(): void
{
    migracionComprasArchivo()->down();
    foreach (ModuloSistema::whereIn('codigo', MIGRACION_COMPRAS_MODULOS)->get() as $modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
}

test('en una base existente crea los módulos y da los permisos de la matriz; dos veces da lo mismo', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionComprasSinNada();
    expect(Schema::hasTable('compras'))->toBeFalse();

    migracionComprasArchivo()->up();
    migracionComprasArchivo()->down();
    migracionComprasArchivo()->up();

    expect(Schema::hasTable('compras'))->toBeTrue()
        ->and(Estado::inicialDe('COMPRAS'))->toBe(Estado::idDe(Estado::PENDIENTE));

    foreach (PerfilesPredefinidos::PERMISOS_COMPRAS as $modulo => $accionesPorPerfil) {
        foreach ($accionesPorPerfil as $perfil => $acciones) {
            expect(migracionComprasAcciones($perfil, $modulo))->toBe(collect($acciones)->sort()->values()->all());
        }
    }
    expect(migracionComprasAcciones(PerfilesPredefinidos::COMPRAS_TESORERIA, 'CONFIRMACION_COMPRAS'))->toBe([])
        ->and(migracionComprasAcciones(PerfilesPredefinidos::MEDICO, 'COMPRAS'))->toBe([])
        ->and(migracionComprasAcciones(PerfilAcceso::ADMINISTRADOR, 'COMPRAS'))->toBe(collect(Permiso::ACCIONES)->sort()->values()->all());
});

test('lo que da la migración es lo mismo que la matriz de los perfiles nuevos', function () {
    foreach (PerfilesPredefinidos::PERMISOS_COMPRAS as $modulo => $accionesPorPerfil) {
        foreach ($accionesPorPerfil as $perfil => $acciones) {
            expect(PerfilesPredefinidos::MATRIZ[$perfil][$modulo] ?? null)->toBe($acciones);
        }
    }
    foreach (PerfilesPredefinidos::MATRIZ as $perfil => $modulos) {
        foreach (MIGRACION_COMPRAS_MODULOS as $modulo) {
            expect($modulos[$modulo] ?? null)->toBe(PerfilesPredefinidos::PERMISOS_COMPRAS[$modulo][$perfil] ?? null);
        }
    }
});

test('en una instalación nueva (sin módulos) no crea módulos: los deja al seeder', function () {
    migracionComprasArchivo()->down();
    expect(DB::table('modulos_sistema')->exists())->toBeFalse();

    migracionComprasArchivo()->up();

    expect(Schema::hasTable('compras'))->toBeTrue()->and(ModuloSistema::whereIn('codigo', MIGRACION_COMPRAS_MODULOS)->exists())->toBeFalse();
});
