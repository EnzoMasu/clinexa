<?php

use App\Models\Persona;
use App\Support\Respaldos;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * clinexa:respaldo de verdad (pg_dump, pg_restore, createdb, dropdb, psql) contra la base de tests
 * clinexa_test, nunca la real, a una carpeta temporal con espacios en la ruta.
 */
beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toBe('clinexa_test');
    $this->carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'clinexa respaldos '.bin2hex(random_bytes(16)); // no uniqid(): choca entre corridas simultáneas
    config(['clinexa.respaldos.carpeta' => $this->carpeta]);
});

afterEach(fn () => File::deleteDirectory($this->carpeta));

function existeBase(string $nombre): bool
{
    return DB::table('pg_database')->where('datname', $nombre)->exists();
}

/**
 * Corre clinexa:respaldo --probar y devuelve [código de salida, salida, base temporal que usó]. Con
 * Artisan::call para leer la salida completa: el nombre de la base es aleatorio.
 *
 * @return array{0: int, 1: string, 2: string}
 */
function respaldoProbar(): array
{
    $codigo = Artisan::call('clinexa:respaldo', ['--probar' => true]);
    $salida = Artisan::output();
    preg_match('/base temporal (clinexa_restore_test_\S+)…/u', $salida, $m);
    expect($m[1] ?? null)->toMatch(Respaldos::PATRON_BASE_DE_PRUEBA);

    return [$codigo, $salida, $m[1]];
}

test('respalda clinexa_test en una ruta con espacios y lo verifica con pg_restore --list', function () {
    $this->artisan('clinexa:respaldo')->assertSuccessful();

    $archivos = File::glob($this->carpeta.DIRECTORY_SEPARATOR.'clinexa-*.dump');
    expect($archivos)->toHaveCount(1)->and(File::size($archivos[0]))->toBeGreaterThan(1000);

    [$tablas] = Respaldos::desdeConfiguracion()->listar($archivos[0]);
    // Una entrada por tabla (no se cuentan además las de sus datos): las mismas que tiene la base.
    expect($tablas)->toBe(DB::table('information_schema.tables')->where('table_schema', 'public')->where('table_type', 'BASE TABLE')->count());
});

test('--probar restaura en una base temporal propia, compara las tablas clave y elimina esa base', function () {
    [$codigo, $salida, $base] = respaldoProbar();

    expect($codigo)->toBe(0)
        ->and($salida)->toContain('La restauración coincide con la base.')
        ->and($salida)->toContain("Base temporal {$base} eliminada.")
        ->and(existeBase($base))->toBeFalse()
        ->and(existeBase('clinexa_test'))->toBeTrue();
});

test('--probar no toca la base temporal de otra corrida', function () {
    // Otra corrida "en curso": su base temporal, creada con las mismas herramientas y guardas.
    $otra = new Respaldos(config('database.connections.pgsql'));
    expect($otra->crearBaseDePrueba()->successful())->toBeTrue();

    try {
        [$codigo, , $base] = respaldoProbar();

        expect($codigo)->toBe(0)
            ->and($base)->not->toBe($otra->baseDePrueba())
            ->and(existeBase($base))->toBeFalse()
            ->and(existeBase($otra->baseDePrueba()))->toBeTrue();
    } finally {
        $otra->eliminarBaseDePrueba();
    }

    expect(existeBase($otra->baseDePrueba()))->toBeFalse();
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

    [$codigo, $salida, $base] = respaldoProbar();

    expect($codigo)->toBe(0)
        ->and($salida)->toContain('Aviso: 1 registros nuevos en la base')
        ->and($salida)->toContain('La restauración coincide con la base.')
        ->and(existeBase($base))->toBeFalse();
});

test('--probar: una diferencia en otra tabla clave es un error, y la base temporal igual se elimina', function () {
    Persona::factory()->create(); // sin confirmar: el respaldo no la tiene

    [$codigo, $salida, $base] = respaldoProbar();

    expect($codigo)->toBe(1)
        ->and($salida)->toContain('La restauración no coincide con la base en: personas')
        ->and($salida)->toContain("Base temporal {$base} eliminada.")
        ->and(existeBase($base))->toBeFalse();
});
