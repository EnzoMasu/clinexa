<?php

use App\Support\Respaldos;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

// Grupo "lento" (docs/pruebas.md): Respaldo (herramientas de PostgreSQL simuladas, archivos de prueba). No corre en el comando rápido.
uses()->group('lento');

/*
 * clinexa:respaldo con las herramientas de PostgreSQL simuladas (Process::fake): no corre pg_dump
 * ni se conecta a ninguna base. La conexión es inventada y apunta a un puerto cerrado: si algo
 * intentara conectarse, fallaría en lugar de llegar a una base real. La prueba de verdad (con
 * pg_dump y --probar) está en tests/Postgres/RespaldoTest.php, contra clinexa_test.
 */

const CONTRASENA_SIMULADA = 'Secreta#123 con espacios';

// Carpeta propia de cada prueba: nombre aleatorio (no uniqid(), que son microsegundos y choca entre procesos en paralelo).
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 18:05:00', 'UTC')); // 15:05 en Paraguay
    $this->carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'clinexa respaldos '.bin2hex(random_bytes(16)).DIRECTORY_SEPARATOR.'sub carpeta';
    config([
        'database.default' => 'pg_simulada',
        'database.connections.pg_simulada' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'base_simulada',
            'username' => 'usuario', 'password' => CONTRASENA_SIMULADA, 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public', 'sslmode' => 'prefer'],
        'clinexa.respaldos.carpeta' => $this->carpeta,
        'clinexa.respaldos.pg_bin' => 'C:\\Program Files\\PostgreSQL\\18\\bin',
    ]);
});

afterEach(function () {
    // Antes de que RefreshDatabase deshaga su transacción (en la conexión por defecto).
    config(['database.default' => 'sqlite']);
    Carbon::setTestNow();
    File::deleteDirectory(dirname($this->carpeta));
});

/** Nombre del ejecutable como lo arma Respaldos::herramienta(): con .exe solo en Windows. */
function respaldoEjecutable(string $nombre): string
{
    return PHP_OS_FAMILY === 'Windows' ? "{$nombre}.exe" : $nombre;
}

/** Simula las herramientas: pg_dump escribe un archivo y pg_restore --list lista 3 tablas. */
function simularHerramientas(string $listado = "; Archive\n201; 1259 16390 TABLE public personas postgres\n202; 1259 16391 TABLE public pacientes postgres\n203; 1259 16392 TABLE public users postgres\n3401; 0 16390 TABLE DATA public personas postgres\n"): void
{
    Process::fake(function (PendingProcess $proceso) use ($listado) {
        $herramienta = basename($proceso->command[0], '.exe');
        if ($herramienta === 'pg_dump') {
            File::put($proceso->command[array_search('-f', $proceso->command, true) + 1], 'PGDMP simulado');

            return Process::result();
        }

        return $herramienta === 'pg_restore' ? Process::result($listado) : Process::result();
    });
}

test('respalda en la carpeta configurada (la crea), con nombre en hora de Paraguay, y verifica el archivo', function () {
    simularHerramientas();

    // El listado simulado tiene 3 tablas y los datos de una (TABLE DATA no cuenta como otra tabla).
    $this->artisan('clinexa:respaldo')->assertSuccessful()
        ->expectsOutputToContain('| 3      |')
        ->expectsOutputToContain('Respaldos en '.$this->carpeta.': 1');

    $ruta = $this->carpeta.DIRECTORY_SEPARATOR.'clinexa-20261008-1505.dump';
    expect(File::exists($ruta))->toBeTrue();

    Process::assertRan(fn (PendingProcess $p) => basename($p->command[0], '.exe') === 'pg_restore' && $p->command === [
        'C:\\Program Files\\PostgreSQL\\18\\bin'.DIRECTORY_SEPARATOR.respaldoEjecutable('pg_restore'), '--list', $ruta,
    ]);
});

test('la contraseña va solo en PGPASSWORD: nunca en los argumentos', function () {
    simularHerramientas();

    $this->artisan('clinexa:respaldo')->assertSuccessful()->doesntExpectOutputToContain(CONTRASENA_SIMULADA);

    Process::assertRan(function (PendingProcess $p) {
        return basename($p->command[0], '.exe') === 'pg_dump'
            && $p->environment === ['PGPASSWORD' => CONTRASENA_SIMULADA]
            && ! str_contains(implode(' ', $p->command), 'Secreta')
            && in_array('-w', $p->command, true) && in_array('-Fc', $p->command, true);
    });
    Process::assertDidntRun(fn (PendingProcess $p) => str_contains(implode(' ', $p->command), 'Secreta'));
});

test('las rutas con espacios van como un único argumento, sin comillas ni consola de por medio', function () {
    simularHerramientas();

    $this->artisan('clinexa:respaldo')->assertSuccessful();

    Process::assertRan(function (PendingProcess $p) {
        $destino = $p->command[array_search('-f', $p->command, true) + 1] ?? null;

        return is_array($p->command)
            && $p->command[0] === 'C:\\Program Files\\PostgreSQL\\18\\bin'.DIRECTORY_SEPARATOR.respaldoEjecutable('pg_dump')
            && $destino === $this->carpeta.DIRECTORY_SEPARATOR.'clinexa-20261008-1505.dump'
            && end($p->command) === 'base_simulada';
    });
});

test('dos respaldos en el mismo minuto no se pisan, y los viejos no se borran', function () {
    simularHerramientas();
    File::ensureDirectoryExists($this->carpeta);
    File::put($this->carpeta.DIRECTORY_SEPARATOR.'clinexa-20260101-0900.dump', 'viejo');

    $this->artisan('clinexa:respaldo')->assertSuccessful();
    $this->artisan('clinexa:respaldo')->assertSuccessful()->expectsOutputToContain(': 3 (los viejos no se borran)');

    expect(collect(File::files($this->carpeta))->map->getFilename()->sort()->values()->all())
        ->toBe(['clinexa-20260101-0900.dump', 'clinexa-20261008-1505-2.dump', 'clinexa-20261008-1505.dump']);
});

test('si pg_dump falla, o el archivo no lista tablas, avisa y termina con error', function () {
    Process::fake(['*' => Process::result(errorOutput: 'conexión rechazada', exitCode: 1)]);
    $this->artisan('clinexa:respaldo')->assertFailed()->expectsOutputToContain('pg_dump falló: conexión rechazada');

    simularHerramientas(listado: "; Archive vacío\n");
    $this->artisan('clinexa:respaldo')->assertFailed()->expectsOutputToContain('está vacío o dañado');
});

test('solo respalda PostgreSQL', function () {
    config(['database.default' => 'sqlite']);
    Process::fake();

    $this->artisan('clinexa:respaldo')->assertFailed()->expectsOutputToContain('no es PostgreSQL');
    Process::assertNothingRan();
});

test('--probar: si dropdb no puede eliminar la base temporal, avisa y queda en el log', function () {
    Log::spy();
    Process::fake(function (PendingProcess $proceso) {
        $herramienta = basename($proceso->command[0], '.exe');
        if ($herramienta === 'pg_dump') {
            File::put($proceso->command[array_search('-f', $proceso->command, true) + 1], 'PGDMP simulado');
        }

        return match ($herramienta) {
            'pg_restore' => Process::result("; Archive\n201; 1259 16390 TABLE public personas postgres\n"),
            'dropdb' => Process::result(errorOutput: 'la base está siendo utilizada', exitCode: 1),
            default => Process::result(),
        };
    });

    $this->artisan('clinexa:respaldo', ['--probar' => true])
        ->expectsOutputToContain('No se pudo eliminar la base temporal clinexa_restore_test_'); // el resto, en el log

    Log::shouldHaveReceived('warning')->withArgs(fn (string $mensaje, array $contexto) => str_contains($mensaje, 'dropdb')
        && preg_match(Respaldos::PATRON_BASE_DE_PRUEBA, $contexto['base']) === 1 && $contexto['codigo_de_salida'] === 1
        && $contexto['error'] === 'la base está siendo utilizada')->atLeast()->once();
});

test('--probar: createdb, pg_restore, psql y dropdb usan la misma base temporal, con nombre único, y se elimina solo esa', function () {
    $comandos = [];
    Process::fake(function (PendingProcess $proceso) use (&$comandos) {
        $comandos[] = $proceso->command;
        if (basename($proceso->command[0], '.exe') === 'pg_dump') {
            File::put($proceso->command[array_search('-f', $proceso->command, true) + 1], 'PGDMP simulado');
        }

        return Process::result(basename($proceso->command[0], '.exe') === 'pg_restore' ? "; Archive\n201; 1259 16390 TABLE public personas postgres\n" : '');
    });

    $this->artisan('clinexa:respaldo', ['--probar' => true]);
    $this->artisan('clinexa:respaldo', ['--probar' => true]);

    $porHerramienta = fn (string $nombre) => collect($comandos)
        ->filter(fn (array $comando) => basename($comando[0], '.exe') === $nombre);
    $creadas = $porHerramienta('createdb')->map(fn (array $c) => end($c))->values()->all();
    $eliminadas = $porHerramienta('dropdb')->map(fn (array $c) => end($c))->values()->all();
    $restauradas = $porHerramienta('pg_restore')->filter(fn (array $c) => in_array('-d', $c, true))
        ->map(fn (array $c) => $c[array_search('-d', $c, true) + 1])->values()->all();

    expect($creadas)->toHaveCount(2)
        ->and($creadas[0])->toMatch(Respaldos::PATRON_BASE_DE_PRUEBA)
        ->and($creadas[1])->toMatch(Respaldos::PATRON_BASE_DE_PRUEBA)
        ->and($creadas[0])->not->toBe($creadas[1])
        // Cada corrida elimina una sola base, la suya: no limpia restos de otras antes de empezar.
        ->and($eliminadas)->toBe($creadas)
        ->and($restauradas)->toBe($creadas);
});

test('--probar: si createdb falla, no elimina nada (esa base, si existe, no es de esta corrida)', function () {
    Process::fake(function (PendingProcess $proceso) {
        $herramienta = basename($proceso->command[0], '.exe');
        if ($herramienta === 'pg_dump') {
            File::put($proceso->command[array_search('-f', $proceso->command, true) + 1], 'PGDMP simulado');
        }

        return match ($herramienta) {
            'pg_restore' => Process::result("; Archive\n201; 1259 16390 TABLE public personas postgres\n"),
            'createdb' => Process::result(errorOutput: 'la base ya existe', exitCode: 1),
            default => Process::result(),
        };
    });

    $this->artisan('clinexa:respaldo', ['--probar' => true])->assertFailed()
        ->expectsOutputToContain(': la base ya existe');

    Process::assertDidntRun(fn (PendingProcess $p) => basename($p->command[0], '.exe') === 'dropdb');
});

describe('guardas de la base temporal', function () {
    /** Respaldos con la conexión simulada y el nombre de base temporal indicado. */
    function respaldoConBaseDePrueba(?string $nombre): Respaldos
    {
        return new Respaldos(config('database.connections.pg_simulada'), $nombre);
    }

    test('cada instancia tiene su base temporal: el prefijo y 16 hexadecimales, distinta cada vez', function () {
        $nombres = collect(range(1, 50))->map(fn () => Respaldos::desdeConfiguracion()->baseDePrueba());

        $nombres->each(fn (string $n) => expect($n)->toMatch(Respaldos::PATRON_BASE_DE_PRUEBA)
            ->and(strlen($n))->toBeLessThanOrEqual(63)); // límite de nombres de PostgreSQL
        expect($nombres->unique())->toHaveCount(50);
    });

    test('no se crea, restaura, cuenta ni elimina una base que no cumple el patrón o está protegida', function (string $nombre) {
        Process::fake();
        $respaldos = respaldoConBaseDePrueba($nombre);

        expect(fn () => $respaldos->crearBaseDePrueba())->toThrow(RuntimeException::class, 'Por seguridad')
            ->and(fn () => $respaldos->restaurarEnPrueba('/tmp/x.dump'))->toThrow(RuntimeException::class, 'Por seguridad')
            ->and(fn () => $respaldos->contarEnPrueba())->toThrow(RuntimeException::class, 'Por seguridad')
            ->and(fn () => $respaldos->eliminarBaseDePrueba())->toThrow(RuntimeException::class, 'Por seguridad');
        Process::assertNothingRan();
    })->with([
        'la configurada' => 'base_simulada',
        'clinexa' => 'clinexa',
        'clinexa_dev' => 'clinexa_dev',
        'clinexa_test' => 'clinexa_test',
        'clinexa_recorrido' => 'clinexa_recorrido',
        'postgres' => 'postgres',
        'template1' => 'template1',
        'el nombre fijo anterior' => 'clinexa_restore_test',
        'prefijo sin sufijo' => 'clinexa_restore_test_',
        'sufijo corto' => 'clinexa_restore_test_0123456789abcde',
        'sufijo largo' => 'clinexa_restore_test_0123456789abcdef0',
        'sufijo en mayúsculas' => 'clinexa_restore_test_0123456789ABCDEF',
        'sufijo no hexadecimal' => 'clinexa_restore_test_0123456789abcdeg',
        'prefijo en mayúsculas' => 'CLINEXA_RESTORE_TEST_0123456789abcdef',
        'espacio adelante' => ' clinexa_restore_test_0123456789abcdef',
        'salto de línea al final' => "clinexa_restore_test_0123456789abcdef\n",
        'otro sufijo agregado' => 'clinexa_restore_test_0123456789abcdef_x',
        'otro prefijo' => 'x_clinexa_restore_test_0123456789abcdef',
        'comodín' => 'clinexa_restore_test_%',
        'inyección' => 'clinexa_restore_test_0123456789abcdef;drop',
        'sin prefijo' => '0123456789abcdef',
        'clinexa_test_ con sufijo' => 'clinexa_test_0123456789abcdef',
    ]);

    test('aunque cumpla el patrón, no se acepta otro nombre que el de la base de esta corrida', function () {
        Process::fake();
        $respaldos = respaldoConBaseDePrueba('clinexa_restore_test_0123456789abcdef');

        expect(fn () => $respaldos->verificarBaseDePrueba('clinexa_restore_test_fedcba9876543210'))->toThrow(RuntimeException::class, 'Por seguridad');
        $respaldos->verificarBaseDePrueba('clinexa_restore_test_0123456789abcdef'); // la suya sí
        Process::assertNothingRan();
    });

    test('ni siquiera una que cumple el patrón si es la base configurada', function () {
        config(['database.connections.pg_simulada.database' => 'clinexa_restore_test_0123456789abcdef']);
        Process::fake();
        $respaldos = respaldoConBaseDePrueba('clinexa_restore_test_0123456789abcdef');

        expect(fn () => $respaldos->eliminarBaseDePrueba())->toThrow(RuntimeException::class, 'Por seguridad')
            ->and(fn () => $respaldos->crearBaseDePrueba())->toThrow(RuntimeException::class, 'Por seguridad');
        Process::assertNothingRan();
    });

    test('con el nombre correcto, dropdb recibe exactamente la base de esta corrida', function () {
        Process::fake();
        $respaldos = Respaldos::desdeConfiguracion();

        $respaldos->eliminarBaseDePrueba();

        Process::assertRanTimes(fn (PendingProcess $p) => basename($p->command[0], '.exe') === 'dropdb', 1);
        Process::assertRan(fn (PendingProcess $p) => basename($p->command[0], '.exe') === 'dropdb' && end($p->command) === $respaldos->baseDePrueba()
            && in_array('--if-exists', $p->command, true));
    });
});
