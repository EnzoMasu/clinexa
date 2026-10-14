<?php

/*
 * La migración de las constancias corre también contra un catálogo SIN los estados EMITIDO y ANULADO (los crea, idempotente)
 * y no falla en la simulación. Pasó una vez que la base real la rechazó con `migrate --pretend`.
 */

use App\Models\Estado;
use App\Models\ModuloSistema;
use Database\Seeders\ModuloSistemaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function mcMigracion(): object
{
    return require base_path('database/migrations/2026_10_17_100001_create_constancias.php');
}

/** Olvida los ids de estados que Estado guarda en memoria (si no, devolvería ids de filas ya borradas). */
function mcOlvidarIds(): void
{
    $propiedad = new ReflectionProperty(Estado::class, 'ids');
    $propiedad->setValue(null, []);
}

afterEach(fn () => mcOlvidarIds());

test('con un catálogo sin EMITIDO ni ANULADO, la migración los crea, arma las tablas y asocia los módulos', function () {
    $this->seed(ModuloSistemaSeeder::class);
    mcMigracion()->down();
    DB::table('estado_modulo')->whereIn('estado_id', Estado::whereIn('codigo', [Estado::EMITIDO, Estado::ANULADO])->pluck('id'))->delete();
    DB::table('estados')->whereIn('codigo', [Estado::EMITIDO, Estado::ANULADO])->delete();
    mcOlvidarIds();
    expect(Estado::whereIn('codigo', [Estado::EMITIDO, Estado::ANULADO])->count())->toBe(0);

    mcMigracion()->up();

    expect(Estado::whereIn('codigo', [Estado::EMITIDO, Estado::ANULADO])->count())->toBe(2)
        ->and(Schema::hasTable('constancias_retiro'))->toBeTrue()->and(Schema::hasTable('constancias_estado'))->toBeTrue();
    foreach (['CONSTANCIAS_RETIRO', 'CONSTANCIAS_ESTADO'] as $modulo) {
        expect(ModuloSistema::where('codigo', $modulo)->sole()->estados()->pluck('codigo')->sort()->values()->all())->toBe(['ANULADO', 'EMITIDO']);
    }
});

test('con los estados ya presentes no crea duplicados (idempotente)', function () {
    mcMigracion()->down();
    $antes = Estado::count();

    mcMigracion()->up();

    expect(Estado::count())->toBe($antes)->and(Estado::where('codigo', Estado::EMITIDO)->count())->toBe(1);
});

test('en la simulación (migrate --pretend), que no devuelve filas, no falla', function () {
    mcMigracion()->down();

    $consultas = DB::pretend(fn () => mcMigracion()->up());

    expect($consultas)->not->toBeEmpty();
});
