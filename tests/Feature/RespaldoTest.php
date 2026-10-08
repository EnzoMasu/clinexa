<?php

use App\Support\Respaldos;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * clinexa:respaldo con las herramientas de PostgreSQL simuladas (Process::fake): no corre pg_dump
 * ni se conecta a ninguna base. La conexión es inventada y apunta a un puerto cerrado: si algo
 * intentara conectarse, fallaría en lugar de llegar a una base real. La prueba de verdad (con
 * pg_dump y --probar) está en tests/Postgres/RespaldoTest.php, contra clinexa_test.
 */

const CONTRASENA_SIMULADA = 'Secreta#123 con espacios';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 18:05:00', 'UTC')); // 15:05 en Paraguay
    $this->carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'clinexa respaldos '.uniqid().DIRECTORY_SEPARATOR.'sub carpeta';
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
        'C:\\Program Files\\PostgreSQL\\18\\bin'.DIRECTORY_SEPARATOR.'pg_restore.exe', '--list', $ruta,
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
            && $p->command[0] === 'C:\\Program Files\\PostgreSQL\\18\\bin'.DIRECTORY_SEPARATOR.'pg_dump.exe'
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

describe('guardas de la base temporal', function () {
    test('solo se crea o elimina exactamente clinexa_restore_test', function (string $nombre) {
        Process::fake();

        expect(fn () => Respaldos::desdeConfiguracion()->eliminarBaseDePrueba($nombre))->toThrow(RuntimeException::class, 'Por seguridad');
        Process::assertNothingRan();
    })->with(['clinexa', 'clinexa_test', 'base_simulada', 'postgres', 'CLINEXA_RESTORE_TEST', 'clinexa_restore_test2', ' clinexa_restore_test']);

    test('ni siquiera clinexa_restore_test si es la base configurada', function () {
        config(['database.connections.pg_simulada.database' => Respaldos::BASE_DE_PRUEBA]);
        Process::fake();

        expect(fn () => Respaldos::desdeConfiguracion()->eliminarBaseDePrueba())->toThrow(RuntimeException::class, 'Por seguridad');
        expect(fn () => Respaldos::desdeConfiguracion()->crearBaseDePrueba())->toThrow(RuntimeException::class, 'Por seguridad');
        Process::assertNothingRan();
    });

    test('con el nombre correcto, dropdb recibe exactamente esa base', function () {
        Process::fake();

        Respaldos::desdeConfiguracion()->eliminarBaseDePrueba();

        Process::assertRan(fn (PendingProcess $p) => basename($p->command[0], '.exe') === 'dropdb' && end($p->command) === 'clinexa_restore_test'
            && in_array('--if-exists', $p->command, true));
    });
});
