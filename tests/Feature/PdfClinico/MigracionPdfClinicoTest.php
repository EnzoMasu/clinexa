<?php

/*
 * La migración del permiso PDF_CLINICO corre también contra un catálogo SIN sus datos (como la de constancias): sin el
 * módulo, sin los estados ACTIVO e INACTIVO, sin módulos en absoluto (instalación nueva) y en la simulación
 * (`migrate --pretend`, que no devuelve filas). Es idempotente y reparte el permiso solo a los perfiles pedidos.
 */

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\Permiso;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Facades\DB;

function mpMigracion(): object
{
    return require base_path('database/migrations/2026_10_18_100001_pdf_clinico.php');
}

/** Olvida los ids de estados que Estado guarda en memoria (si no, devolvería ids de filas ya borradas). */
function mpOlvidarIds(): void
{
    $propiedad = new ReflectionProperty(Estado::class, 'ids');
    $propiedad->setValue(null, []);
}

afterEach(fn () => mpOlvidarIds());

/** Si el perfil de ese código tiene EXPORTAR sobre PDF_CLINICO. */
function mpTiene(string $codigoPerfil): bool
{
    return in_array('PDF_CLINICO:EXPORTAR', PerfilAcceso::where('codigo', $codigoPerfil)->sole()->clavesPermisos(), true);
}

/** Quita el módulo PDF_CLINICO y todo lo que cuelga de él, como en una base anterior a esta fase. */
function mpSinElModulo(): void
{
    $modulo = ModuloSistema::where('codigo', 'PDF_CLINICO')->first();
    if ($modulo) {
        DB::table('perfil_permiso')->whereIn('permiso_id', Permiso::where('modulo_sistema_id', $modulo->id)->pluck('id'))->delete();
        Permiso::where('modulo_sistema_id', $modulo->id)->delete();
        DB::table('estado_modulo')->where('modulo_sistema_id', $modulo->id)->delete();
        $modulo->delete();
    }
}

test('en una base existente sin el módulo: lo crea con sus estados y lo reparte a Médico, Enfermería y Supervisión (no a Recepción)', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    mpSinElModulo();
    expect(ModuloSistema::where('codigo', 'PDF_CLINICO')->exists())->toBeFalse();

    mpMigracion()->up();

    $modulo = ModuloSistema::where('codigo', 'PDF_CLINICO')->sole();
    expect($modulo->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ACTIVO', 'INACTIVO']);
    foreach ([PerfilesPredefinidos::MEDICO, PerfilesPredefinidos::ENFERMERIA, PerfilesPredefinidos::SUPERVISION_MEDICA] as $codigo) {
        expect(mpTiene($codigo))->toBeTrue();
    }
    foreach ([PerfilesPredefinidos::RECEPCION, PerfilesPredefinidos::GERENCIA, PerfilesPredefinidos::AUDITOR, PerfilesPredefinidos::CAJA] as $codigo) {
        expect(mpTiene($codigo))->toBeFalse();
    }
    expect(mpTiene(PerfilAcceso::ADMINISTRADOR))->toBeTrue();
});

test('con un catálogo al que le falta el estado INACTIVO, lo crea y asocia los dos al módulo', function () {
    // ACTIVO no puede faltar si hay módulos (todos lo usan); INACTIVO sí: solo lo referencia estado_modulo.
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    mpSinElModulo();
    $inactivo = Estado::where('codigo', Estado::INACTIVO)->pluck('id');
    DB::table('estado_modulo')->whereIn('estado_id', $inactivo)->delete();
    DB::table('estados')->whereIn('id', $inactivo)->delete();
    mpOlvidarIds();
    expect(Estado::where('codigo', Estado::INACTIVO)->count())->toBe(0);

    mpMigracion()->up();

    expect(Estado::where('codigo', Estado::INACTIVO)->count())->toBe(1)
        ->and(ModuloSistema::where('codigo', 'PDF_CLINICO')->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ACTIVO', 'INACTIVO']);
});

test('en una instalación nueva (sin módulos) no hace nada y no falla', function () {
    expect(ModuloSistema::count())->toBe(0);

    mpMigracion()->up();

    expect(ModuloSistema::count())->toBe(0);
});

test('es idempotente: correrla de nuevo no duplica nada ni pisa lo que se quitó desde la matriz', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    mpMigracion()->up();
    $modulos = ModuloSistema::count();
    $permisos = Permiso::count();

    mpMigracion()->up();

    expect(ModuloSistema::count())->toBe($modulos)->and(Permiso::count())->toBe($permisos);
});

test('en la simulación (migrate --pretend), que no devuelve filas, no falla', function () {
    $this->seed(ModuloSistemaSeeder::class);
    PerfilesPredefinidos::asegurar();
    mpSinElModulo();

    $consultas = DB::pretend(fn () => mpMigracion()->up());

    expect($consultas)->not->toBeEmpty();
});
