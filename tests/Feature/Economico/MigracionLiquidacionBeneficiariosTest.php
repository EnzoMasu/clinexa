<?php

/*
 * Las migraciones de la liquidación a beneficiarios (2026_11_11_100001) y de la caja de Tesorería (2026_11_11_100002): solo suman los
 * permisos de la matriz (repetibles, sin quitar ni cambiar ningún otro), crean los cuatro módulos con sus estados, en una instalación
 * nueva no tocan el catálogo y en la simulación (`--pretend`) no fallan. Con datos ya cargados, en tests/Postgres. Datos inventados.
 * Prefijo "migracionLiqBenef".
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

function migracionLiqBenefEsquema(): object
{
    return require base_path('database/migrations/2026_11_11_100001_create_liquidacion_beneficiarios.php');
}

function migracionLiqBenefTesoreria(): object
{
    return require base_path('database/migrations/2026_11_11_100002_tesoreria_abre_caja.php');
}

afterEach(function () {
    (new ReflectionProperty(Estado::class, 'ids'))->setValue(null, []);
    Carbon::setTestNow();
});

function migracionLiqBenefClaves(): array
{
    return PerfilAcceso::orderBy('id')->get()->mapWithKeys(fn (PerfilAcceso $perfil) => [$perfil->codigo ?? $perfil->nombre => collect($perfil->clavesPermisos())->sort()->values()->all()])->all();
}

/** Lo que la matriz da a ese perfil en esos módulos, como "MÓDULO:ACCIÓN". */
function migracionLiqBenefEsperadas(string $perfil, array $modulos): array
{
    return collect($modulos)->flatMap(fn (array $porPerfil, string $modulo) => collect($porPerfil[$perfil] ?? [])->map(fn (string $accion) => "{$modulo}:{$accion}"))
        ->sort()->values()->all();
}

test('solo suman los permisos de la matriz (Tesorería: CAJAS y SALIDAS_CAJA, sin COBROS), son repetibles y no quitan nada', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    // Como antes de esta fase: los módulos nuevos sin permisos y Tesorería sin caja.
    $ids = Permiso::whereIn('modulo_sistema_id', ModuloSistema::whereIn('codigo', array_keys(PerfilesPredefinidos::MODULOS_LIQUIDACION_BENEFICIARIOS))->pluck('id'))->pluck('id');
    DB::table('perfil_permiso')->whereIn('permiso_id', $ids)->delete();
    $tesoreria = PerfilAcceso::where('codigo', PerfilesPredefinidos::COMPRAS_TESORERIA)->sole();
    DB::table('perfil_permiso')->where('perfil_acceso_id', $tesoreria->id)
        ->whereIn('permiso_id', Permiso::whereIn('modulo_sistema_id', ModuloSistema::whereIn('codigo', ['CAJAS', 'SALIDAS_CAJA'])->pluck('id'))->pluck('id'))->delete();
    $antes = migracionLiqBenefClaves();

    foreach ([1, 2] as $vez) {
        migracionLiqBenefEsquema()->down(); // sin liquidaciones cargadas: baja y sube de nuevo
        migracionLiqBenefEsquema()->up();
        migracionLiqBenefTesoreria()->up();
    }

    $sumados = [...PerfilesPredefinidos::PERMISOS_LIQUIDACION_BENEFICIARIOS, ...PerfilesPredefinidos::PERMISOS_TESORERIA_CAJA];
    foreach (migracionLiqBenefClaves() as $perfil => $claves) {
        $nuevas = collect(array_diff($claves, $antes[$perfil] ?? []))->sort()->values()->all();
        $esperadas = $perfil === PerfilAcceso::ADMINISTRADOR
            ? $nuevas // el Administrador tiene siempre todo
            : collect(migracionLiqBenefEsperadas($perfil, $sumados))->diff($antes[$perfil] ?? [])->values()->all();
        expect($nuevas)->toBe($esperadas, $perfil)->and(array_diff($antes[$perfil] ?? [], $claves))->toBe([], "{$perfil} no pierde permisos");
    }
    $despues = migracionLiqBenefClaves();
    expect($despues[PerfilesPredefinidos::COMPRAS_TESORERIA])->toContain('CAJAS:CREAR')->toContain('SALIDAS_CAJA:CREAR')->toContain('PAGOS_BENEFICIARIOS:CREAR')
        ->not->toContain('COBROS:VER')->not->toContain('CIERRES_CAJA:EDITAR')
        ->and($despues[PerfilesPredefinidos::AUDITOR])->toBe($antes[PerfilesPredefinidos::AUDITOR])
        ->and($despues[PerfilesPredefinidos::MEDICO])->toContain('MIS_LIQUIDACIONES:VER');
    expect(ModuloSistema::where('codigo', 'LIQUIDACIONES_BENEFICIARIOS')->sole()->estados()->pluck('codigo')->sort()->values()->all())
        ->toBe(['ANULADO', 'APROBADO', 'EN_PREPARACION', 'PAGADO', 'PARCIAL', 'PENDIENTE_APROBACION']);
});

test('en una instalación nueva (sin módulos ni perfiles) arman el esquema y no tocan el catálogo; en simulación no leen filas', function () {
    migracionLiqBenefEsquema()->down();
    expect(ModuloSistema::count())->toBe(0);

    $sentencias = DB::pretend(fn () => migracionLiqBenefEsquema()->up());
    expect($sentencias)->not->toBeEmpty()->and(Schema::hasTable('liquidaciones_beneficiario'))->toBeFalse();

    migracionLiqBenefEsquema()->up();
    migracionLiqBenefTesoreria()->up();
    expect(Schema::hasTable('pagos_beneficiario'))->toBeTrue()->and(Schema::hasColumn('pagos_beneficiario', 'cuenta_por_pagar_id'))->toBeTrue()
        ->and(ModuloSistema::count())->toBe(0);
});
