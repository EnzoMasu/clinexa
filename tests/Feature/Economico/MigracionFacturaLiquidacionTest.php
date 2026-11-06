<?php

/*
 * Las migraciones de la factura de la liquidación (2026_11_10_100001 y _100002). Con facturas, cobros, cajas y liquidaciones ya
 * cargados, en tests/Postgres/FacturaLiquidacionTest.php (la base real es PostgreSQL; SQLite reconstruye la tabla). La de permisos solo suma FACTURAS_LIQUIDACION a Facturación y convenios, es repetible y no
 * toca otros permisos; en una instalación nueva no tocan el catálogo y en la simulación (`--pretend`) no fallan. Datos inventados.
 * Prefijo "migracionFacturaLiquidacion".
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

function migracionFacturaLiquidacionEsquema(): object
{
    return require base_path('database/migrations/2026_11_10_100001_factura_de_liquidacion.php');
}

function migracionFacturaLiquidacionPermisos(): object
{
    return require base_path('database/migrations/2026_11_10_100002_permisos_factura_liquidacion.php');
}

function migracionFacturaLiquidacionOlvidarIds(): void
{
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
}

afterEach(function () {
    migracionFacturaLiquidacionOlvidarIds();
    Carbon::setTestNow();
});

/** "MÓDULO:ACCIÓN" de cada perfil, para comparar antes y después. */
function migracionFacturaLiquidacionClaves(): array
{
    return PerfilAcceso::orderBy('id')->get()->mapWithKeys(fn (PerfilAcceso $perfil) => [$perfil->codigo ?? $perfil->nombre => collect($perfil->clavesPermisos())->sort()->values()->all()])->all();
}

function migracionFacturaLiquidacionIndices(): array
{
    return collect(Schema::getIndexes('facturas'))->pluck('name')->sort()->values()->all();
}

test('la de permisos solo suma FACTURAS_LIQUIDACION a Facturación y convenios, es repetible y no toca ningún otro permiso', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    $modulo = ModuloSistema::where('codigo', 'FACTURAS_LIQUIDACION')->sole();
    // Como antes de esta fase: el módulo sin permisos asignados.
    DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
    $antes = migracionFacturaLiquidacionClaves();

    migracionFacturaLiquidacionPermisos()->up();
    migracionFacturaLiquidacionPermisos()->up();

    $despues = migracionFacturaLiquidacionClaves();
    foreach ($despues as $perfil => $claves) {
        $nuevas = array_values(array_diff($claves, $antes[$perfil] ?? []));
        $esperadas = match ($perfil) {
            PerfilesPredefinidos::FACTURACION => ['FACTURAS_LIQUIDACION:CREAR', 'FACTURAS_LIQUIDACION:DESACTIVAR', 'FACTURAS_LIQUIDACION:VER'],
            PerfilAcceso::ADMINISTRADOR => collect(Permiso::where('modulo_sistema_id', $modulo->id)->pluck('accion'))->map(fn ($accion) => "FACTURAS_LIQUIDACION:{$accion}")->sort()->values()->all(),
            default => [],
        };
        expect(collect($nuevas)->sort()->values()->all())->toBe($esperadas, $perfil)
            ->and(array_diff($antes[$perfil] ?? [], $claves))->toBe([], "{$perfil} no pierde permisos");
    }
    expect($despues[PerfilesPredefinidos::CAJA])->not->toContain('FACTURAS_LIQUIDACION:CREAR')
        ->and($despues[PerfilesPredefinidos::GERENCIA])->toContain('ANULACIONES_FACTURA:EDITAR')->toContain('FACTURAS:VER')->toContain('LIQUIDACIONES:VER');
    // Lo que suma es lo mismo que la matriz.
    foreach (PerfilesPredefinidos::PERMISOS_FACTURAS_LIQUIDACION as $perfil => $acciones) {
        expect($acciones)->toBe(PerfilesPredefinidos::MATRIZ[$perfil]['FACTURAS_LIQUIDACION']);
    }
});

test('en una instalación nueva (sin módulos ni perfiles) arman el esquema y no tocan el catálogo', function () {
    migracionFacturaLiquidacionEsquema()->down();
    expect(ModuloSistema::count())->toBe(0);

    migracionFacturaLiquidacionEsquema()->up();
    migracionFacturaLiquidacionPermisos()->up();

    expect(Schema::hasColumn('facturas', 'liquidacion_id'))->toBeTrue()->and(ModuloSistema::count())->toBe(0);
});

test('con el catálogo existente crea el módulo con sus estados; en simulación (--pretend) no lee filas ni cambia nada', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    migracionFacturaLiquidacionEsquema()->down();
    $modulo = ModuloSistema::where('codigo', 'FACTURAS_LIQUIDACION')->sole();
    DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
    Permiso::where('modulo_sistema_id', $modulo->id)->delete();
    DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
    $modulo->delete();
    [$modulos, $permisos] = [ModuloSistema::count(), Permiso::count()];

    $sentencias = DB::pretend(fn () => migracionFacturaLiquidacionEsquema()->up());
    expect($sentencias)->not->toBeEmpty()->and(Schema::hasColumn('facturas', 'liquidacion_id'))->toBeFalse()
        ->and([ModuloSistema::count(), Permiso::count()])->toBe([$modulos, $permisos]);

    migracionFacturaLiquidacionEsquema()->up();
    migracionFacturaLiquidacionPermisos()->up();
    expect(ModuloSistema::where('codigo', 'FACTURAS_LIQUIDACION')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ANULADO', 'COBRADO', 'EMITIDO'])
        ->and(PerfilAcceso::where('codigo', PerfilesPredefinidos::FACTURACION)->sole()->clavesPermisos())->toContain('FACTURAS_LIQUIDACION:CREAR');
});
