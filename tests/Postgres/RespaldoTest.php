<?php

use App\Models\Persona;
use App\Support\Respaldos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * clinexa:respaldo de verdad (pg_dump, pg_restore, createdb, dropdb, psql) contra la base de tests
 * clinexa_test, nunca la real, a una carpeta temporal con espacios en la ruta.
 */
beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toBe('clinexa_test');
    $this->carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'clinexa respaldos '.uniqid();
    config(['clinexa.respaldos.carpeta' => $this->carpeta]);
});

afterEach(fn () => File::deleteDirectory($this->carpeta));

function existeBase(string $nombre): bool
{
    return DB::table('pg_database')->where('datname', $nombre)->exists();
}

test('respalda clinexa_test en una ruta con espacios y lo verifica con pg_restore --list', function () {
    $this->artisan('clinexa:respaldo')->assertSuccessful();

    $archivos = File::glob($this->carpeta.DIRECTORY_SEPARATOR.'clinexa-*.dump');
    expect($archivos)->toHaveCount(1)->and(File::size($archivos[0]))->toBeGreaterThan(1000);

    [$tablas] = Respaldos::desdeConfiguracion()->listar($archivos[0]);
    // Una entrada por tabla (no se cuentan además las de sus datos): las mismas que tiene la base.
    expect($tablas)->toBe(DB::table('information_schema.tables')->where('table_schema', 'public')->where('table_type', 'BASE TABLE')->count());
});

test('--probar restaura en clinexa_restore_test, compara las tablas clave y elimina la base temporal', function () {
    expect(existeBase(Respaldos::BASE_DE_PRUEBA))->toBeFalse();

    $this->artisan('clinexa:respaldo', ['--probar' => true])->assertSuccessful()
        ->expectsOutputToContain('La restauración coincide con la base.')
        ->expectsOutputToContain('Base temporal clinexa_restore_test eliminada.');

    expect(existeBase(Respaldos::BASE_DE_PRUEBA))->toBeFalse()
        ->and(existeBase('clinexa_test'))->toBeTrue();
});

test('un archivo dañado se detecta', function () {
    File::ensureDirectoryExists($this->carpeta);
    $danado = $this->carpeta.DIRECTORY_SEPARATOR.'dañado con espacios.dump';
    File::put($danado, 'esto no es un respaldo');

    [$tablas, $resultado] = Respaldos::desdeConfiguracion()->listar($danado);

    expect($tablas)->toBe(0)->and($resultado->successful())->toBeFalse();
});

test('--probar: si la base tiene más registros de auditoría que el respaldo, avisa en lugar de fallar', function () {
    // Sin confirmar (transacción del test): pg_dump no lo ve, la comparación sí. Como alguien que usa
    // el sistema mientras se respalda.
    DB::table('logs_auditoria')->insert(['tabla_afectada' => 'personas', 'accion' => 'VER', 'fecha_hora' => now()]);

    $this->artisan('clinexa:respaldo', ['--probar' => true])->assertSuccessful()
        ->expectsOutputToContain('Aviso: 1 registros nuevos en la base')
        ->expectsOutputToContain('La restauración coincide con la base.');

    expect(existeBase(Respaldos::BASE_DE_PRUEBA))->toBeFalse();
});

test('--probar: una diferencia en otra tabla clave es un error, y la base temporal igual se elimina', function () {
    Persona::factory()->create(); // sin confirmar: el respaldo no la tiene

    $this->artisan('clinexa:respaldo', ['--probar' => true])->assertFailed()
        ->expectsOutputToContain('La restauración no coincide con la base en: personas')
        ->expectsOutputToContain('Base temporal clinexa_restore_test eliminada.');

    expect(existeBase(Respaldos::BASE_DE_PRUEBA))->toBeFalse();
});
