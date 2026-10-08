<?php

namespace App\Console\Commands;

use App\Support\Respaldos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Respaldo de la base configurada con pg_dump (formato custom), a una carpeta fuera del repositorio
 * (config clinexa.respaldos.carpeta). Verifica el archivo con pg_restore --list. Con --probar,
 * además lo restaura en clinexa_restore_test, compara filas de las tablas clave con la base y
 * elimina esa base temporal. Nunca borra respaldos viejos. Ver docs/respaldo.md.
 */
class Respaldo extends Command
{
    protected $signature = 'clinexa:respaldo
        {--probar : Restaurar el respaldo en una base temporal y comparar filas con la base}';

    protected $description = 'Respalda la base (pg_dump -Fc) en la carpeta de respaldos y verifica el archivo';

    public function handle(): int
    {
        try {
            $respaldos = Respaldos::desdeConfiguracion();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $carpeta = Respaldos::carpeta();
        File::ensureDirectoryExists($carpeta);
        $ruta = Respaldos::rutaNueva($carpeta);

        $this->info("Respaldando la base {$respaldos->base()}…");
        $volcado = $respaldos->volcar($ruta);
        if (! $volcado->successful() || ! File::exists($ruta)) {
            $this->error('pg_dump falló: '.trim($volcado->errorOutput() ?: $volcado->output()));

            return self::FAILURE;
        }

        [$tablas, $lista] = $respaldos->listar($ruta);
        if (! $lista->successful() || $tablas === 0 || File::size($ruta) === 0) {
            $this->error("El respaldo {$ruta} está vacío o dañado (pg_restore --list: ".trim($lista->errorOutput() ?: 'sin tablas').'). No lo use.');

            return self::FAILURE;
        }

        $this->table(['Respaldo', 'Tamaño', 'Tablas'], [[$ruta, $this->tamano(File::size($ruta)), $tablas]]);
        $this->line('Respaldos en '.$carpeta.': '.Respaldos::cantidadEn($carpeta).' (los viejos no se borran).');

        return $this->option('probar') ? $this->probar($respaldos, $ruta) : self::SUCCESS;
    }

    /** Restaura en clinexa_restore_test, compara filas y elimina esa base (siempre, aunque algo falle). */
    private function probar(Respaldos $respaldos, string $ruta): int
    {
        $base = Respaldos::BASE_DE_PRUEBA;
        $this->info("Probando la restauración en la base temporal {$base}…");

        try {
            $respaldos->eliminarBaseDePrueba(); // por si quedó de una prueba anterior interrumpida
            $creada = $respaldos->crearBaseDePrueba();
            if (! $creada->successful()) {
                $this->error("No se pudo crear la base {$base}: ".trim($creada->errorOutput()));

                return self::FAILURE;
            }

            $restaurada = $respaldos->restaurarEnPrueba($ruta);
            if (! $restaurada->successful()) {
                // pg_restore también termina con error por avisos menores; lo que decide es la comparación.
                $this->warn('pg_restore informó problemas: '.trim($restaurada->errorOutput()));
            }

            return $this->comparar($respaldos->contarEnPrueba());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $eliminada = $respaldos->eliminarBaseDePrueba();
            $eliminada->successful()
                ? $this->line("Base temporal {$base} eliminada.")
                : $this->error("No se pudo eliminar la base temporal {$base}: ".trim($eliminada->errorOutput()).' Elimínela a mano.');
        }
    }

    /** @param  array<string, int|null>  $restauradas */
    private function comparar(array $restauradas): int
    {
        $filas = [];
        $diferencias = [];
        foreach (Respaldos::TABLAS_CLAVE as $tabla) {
            $enBase = Schema::hasTable($tabla) ? DB::table($tabla)->count() : null;
            $enRespaldo = $restauradas[$tabla] ?? null;

            $estado = match (true) {
                $enBase === $enRespaldo => 'igual',
                // El log solo crece: si alguien usó el sistema mientras se respaldaba, la base tiene más.
                $tabla === 'logs_auditoria' && $enBase !== null && $enRespaldo !== null && $enBase > $enRespaldo => 'aviso',
                default => 'distinta',
            };
            if ($estado === 'distinta') {
                $diferencias[] = $tabla;
            }

            $filas[] = [$tabla, $enBase ?? '(no existe)', $enRespaldo ?? '(no existe)', match ($estado) {
                'igual' => 'Igual',
                'aviso' => 'Aviso: '.($enBase - $enRespaldo).' registros nuevos en la base (alguien usó el sistema mientras se respaldaba)',
                default => 'DISTINTA',
            }];
        }

        $this->table(['Tabla', 'Filas en la base', 'Filas en el respaldo', 'Resultado'], $filas);

        if ($diferencias !== []) {
            $this->error('La restauración no coincide con la base en: '.implode(', ', $diferencias).'. Revise el respaldo antes de confiar en él.');

            return self::FAILURE;
        }

        $this->info('La restauración coincide con la base.');

        return self::SUCCESS;
    }

    private function tamano(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.').' MB' : number_format($bytes / 1024, 1, ',', '.').' KB';
    }
}
