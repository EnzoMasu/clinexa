<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Respaldos de la base PostgreSQL con las herramientas de PostgreSQL (pg_dump, pg_restore,
 * createdb, dropdb, psql). Los usa el comando clinexa:respaldo.
 *
 * - Los procesos se lanzan con la lista de argumentos (sin pasar por una consola): las rutas con
 *   espacios no necesitan comillas y nada se interpreta.
 * - La contraseña va solo en la variable de entorno PGPASSWORD del proceso hijo: nunca en la línea
 *   de comandos, en un archivo ni en un log. Con --no-password (-w), si falta, falla en lugar de
 *   quedarse esperando que alguien la escriba.
 */
final class Respaldos
{
    /** La única base que --probar puede crear y eliminar. */
    public const BASE_DE_PRUEBA = 'clinexa_restore_test';

    /** Bases que nunca se eliminan, se llamen como se llamen en la configuración. */
    public const BASES_PROTEGIDAS = ['clinexa', 'clinexa_test', 'postgres', 'template0', 'template1'];

    /** Tablas cuya cantidad de filas se compara al probar la restauración. */
    public const TABLAS_CLAVE = ['personas', 'pacientes', 'historias_clinicas', 'consultas', 'logs_auditoria', 'users'];

    /** @param  array{host: string, port: int|string, username: string, password: ?string, database: string}  $conexion */
    public function __construct(private readonly array $conexion) {}

    /** Con la conexión configurada por defecto (tiene que ser PostgreSQL). */
    public static function desdeConfiguracion(): self
    {
        $nombre = config('database.default');
        $conexion = config("database.connections.{$nombre}");
        if (($conexion['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException("La conexión configurada ({$nombre}) no es PostgreSQL: solo se respaldan bases PostgreSQL.");
        }

        return new self($conexion);
    }

    public function base(): string
    {
        return $this->conexion['database'];
    }

    public static function carpeta(): string
    {
        return rtrim((string) config('clinexa.respaldos.carpeta'), '\\/');
    }

    /** clinexa-AAAAMMDD-HHMM.dump en hora de Paraguay; si ya existe (mismo minuto), -2, -3, ... */
    public static function rutaNueva(string $carpeta, ?Carbon $momento = null): string
    {
        $base = 'clinexa-'.($momento ?? Carbon::now(config('app.zona_horaria_local')))->format('Ymd-Hi');
        $ruta = $carpeta.DIRECTORY_SEPARATOR."{$base}.dump";
        for ($n = 2; File::exists($ruta); $n++) {
            $ruta = $carpeta.DIRECTORY_SEPARATOR."{$base}-{$n}.dump";
        }

        return $ruta;
    }

    /** Respaldos (*.dump) que hay en la carpeta. */
    public static function cantidadEn(string $carpeta): int
    {
        return File::isDirectory($carpeta) ? count(File::glob($carpeta.DIRECTORY_SEPARATOR.'*.dump')) : 0;
    }

    /** Ruta de una herramienta de PostgreSQL (pg_dump, ...). */
    public static function herramienta(string $nombre): string
    {
        $carpeta = (string) config('clinexa.respaldos.pg_bin');
        if ($carpeta === '' && PHP_OS_FAMILY === 'Windows') {
            // La versión más nueva instalada en el lugar por defecto.
            $carpeta = collect(File::glob('C:\\Program Files\\PostgreSQL\\*\\bin'))
                ->filter(fn (string $bin) => File::exists($bin.'\\pg_dump.exe'))
                ->sortBy(fn (string $bin) => (int) basename(dirname($bin)))
                ->last() ?? '';
        }

        $ejecutable = PHP_OS_FAMILY === 'Windows' ? "{$nombre}.exe" : $nombre;

        return $carpeta === '' ? $ejecutable : rtrim($carpeta, '\\/').DIRECTORY_SEPARATOR.$ejecutable;
    }

    /** pg_dump en formato custom (-Fc) de la base configurada. */
    public function volcar(string $ruta): ProcessResult
    {
        return $this->correr(['pg_dump', '-Fc', ...$this->conectar(), '-f', $ruta, $this->base()]);
    }

    /**
     * pg_restore --list del archivo: [cantidad de tablas, resultado]. Un archivo vacío o corrupto
     * hace fallar a pg_restore o no lista ninguna tabla.
     *
     * @return array{0: int, 1: ProcessResult}
     */
    public function listar(string $ruta): array
    {
        $resultado = $this->correr(['pg_restore', '--list', $ruta]);
        // Las entradas TABLE (la definición de cada tabla), no las TABLE DATA (sus filas).
        $tablas = preg_match_all('/^\d+;\s+\d+\s+\d+\s+TABLE\s+(?!DATA\s)/m', $resultado->output());

        return [$resultado->successful() ? $tablas : 0, $resultado];
    }

    public function crearBaseDePrueba(): ProcessResult
    {
        $this->verificarBaseDePrueba(self::BASE_DE_PRUEBA);

        return $this->correr(['createdb', ...$this->conectar(), self::BASE_DE_PRUEBA]);
    }

    /** Elimina la base de prueba (si existe). Solo esa: ver verificarBaseDePrueba. */
    public function eliminarBaseDePrueba(string $nombre = self::BASE_DE_PRUEBA): ProcessResult
    {
        $this->verificarBaseDePrueba($nombre);

        return $this->correr(['dropdb', ...$this->conectar(), '--if-exists', '--force', $nombre]);
    }

    public function restaurarEnPrueba(string $ruta): ProcessResult
    {
        $this->verificarBaseDePrueba(self::BASE_DE_PRUEBA);

        return $this->correr(['pg_restore', ...$this->conectar(), '--no-owner', '--no-privileges', '-d', self::BASE_DE_PRUEBA, $ruta]);
    }

    /**
     * Filas de las tablas clave en la base de prueba (tabla => cantidad, o null si la tabla no está).
     *
     * @return array<string, int|null>
     */
    public function contarEnPrueba(): array
    {
        $this->verificarBaseDePrueba(self::BASE_DE_PRUEBA);

        $resultado = $this->correr(['psql', ...$this->conectar(), '-X', '-A', '-t', '-F', '|', '-d', self::BASE_DE_PRUEBA, '-c', $this->consultaConteo()]);
        if (! $resultado->successful()) {
            throw new RuntimeException('No se pudieron contar las filas en la base de prueba: '.trim($resultado->errorOutput()));
        }

        $contadas = collect(preg_split('/\R/', trim($resultado->output())))
            ->filter()
            ->mapWithKeys(function (string $linea) {
                [$tabla, $filas] = explode('|', $linea) + [1 => '0'];

                return [$tabla => (int) $filas];
            });

        // Las que no están en el respaldo quedan en null.
        return collect(self::TABLAS_CLAVE)->mapWithKeys(fn (string $tabla) => [$tabla => $contadas[$tabla] ?? null])->all();
    }

    /**
     * Guarda dura: la única base que se crea, restaura y elimina es exactamente clinexa_restore_test,
     * y nunca la configurada, la de tests ni las del sistema.
     */
    public function verificarBaseDePrueba(string $nombre): void
    {
        if ($nombre !== self::BASE_DE_PRUEBA || $nombre === $this->base() || in_array($nombre, self::BASES_PROTEGIDAS, true)) {
            throw new RuntimeException('Por seguridad, solo se puede crear o eliminar la base '.self::BASE_DE_PRUEBA." (se pidió \"{$nombre}\"; la configurada es \"{$this->base()}\").");
        }
    }

    /**
     * Filas de cada tabla clave que exista, en una sola consulta: query_to_xml arma el count(*) de
     * cada una por nombre (un count(*) directo sobre una tabla que no existe haría fallar toda la consulta).
     */
    private function consultaConteo(): string
    {
        $tablas = "'".implode("','", self::TABLAS_CLAVE)."'";

        return <<<SQL
            select t.nombre, (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from public.%I', t.nombre), false, true, '')))[1]::text::bigint
            from unnest(array[{$tablas}]) as t(nombre)
            where to_regclass('public.' || t.nombre) is not null
            SQL;
    }

    /** -h, -p, -U y -w (sin preguntar la contraseña: va en PGPASSWORD). */
    private function conectar(): array
    {
        return ['-h', (string) $this->conexion['host'], '-p', (string) $this->conexion['port'], '-U', (string) $this->conexion['username'], '-w'];
    }

    /** Corre una herramienta de PostgreSQL con la contraseña solo en PGPASSWORD. */
    private function correr(array $argumentos): ProcessResult
    {
        $argumentos[0] = self::herramienta($argumentos[0]);

        return Process::env(['PGPASSWORD' => (string) ($this->conexion['password'] ?? '')])
            ->timeout(1800)
            ->run($argumentos);
    }
}
