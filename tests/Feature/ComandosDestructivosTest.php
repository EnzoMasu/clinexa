<?php

use App\Providers\AppServiceProvider;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * migrate:fresh, migrate:refresh, migrate:reset y db:wipe quedan bloqueados fuera de la suite de
 * tests. Nada de esto corre un comando destructivo contra una base que importe: la prueba del
 * bloqueo usa una base SQLite en memoria creada acá mismo ("descartable").
 */

/** El valor de $prohibitedFromRunning de un comando (estático protegido del trait Prohibitable). */
function estaBloqueado(string $comando): bool
{
    return (fn () => static::$prohibitedFromRunning)->bindTo(null, $comando)();
}

test('la condición: bloquea fuera de los tests y no dentro', function () {
    expect(AppServiceProvider::bloquearComandosDestructivos(enTests: false))->toBeTrue()
        ->and(AppServiceProvider::bloquearComandosDestructivos(enTests: true))->toBeFalse();
});

test('son exactamente fresh, refresh, reset y wipe; migrate:rollback no se bloquea', function () {
    expect(collect(AppServiceProvider::COMANDOS_DESTRUCTIVOS)->map(fn ($c) => class_basename($c))->all())
        ->toBe(['FreshCommand', 'RefreshCommand', 'ResetCommand', 'WipeCommand']);

    // Dentro de la suite (como ahora) ninguno está bloqueado: RefreshDatabase usa migrate:fresh.
    foreach (AppServiceProvider::COMANDOS_DESTRUCTIVOS as $comando) {
        expect(estaBloqueado($comando))->toBeFalse();
    }
    expect(estaBloqueado(RollbackCommand::class))->toBeFalse();
});

test('bloqueados, no hacen nada: db:wipe y migrate:fresh sobre una base descartable en memoria', function () {
    config(['database.connections.descartable' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    Schema::connection('descartable')->create('testigo', fn ($tabla) => $tabla->id());

    // Lo mismo que hace boot() fuera de los tests.
    foreach (AppServiceProvider::COMANDOS_DESTRUCTIVOS as $comando) {
        $comando::prohibit(AppServiceProvider::bloquearComandosDestructivos(enTests: false));
    }

    try {
        $this->artisan('db:wipe', ['--database' => 'descartable', '--force' => true])
            ->expectsOutputToContain('This command is prohibited from running in this environment.')
            ->assertFailed();
        $this->artisan('migrate:fresh', ['--database' => 'descartable', '--force' => true])->assertFailed();

        expect(Schema::connection('descartable')->hasTable('testigo'))->toBeTrue();
    } finally {
        foreach (AppServiceProvider::COMANDOS_DESTRUCTIVOS as $comando) {
            $comando::prohibit(false);
        }
        DB::purge('descartable');
    }
});
