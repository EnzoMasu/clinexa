<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Únicas bases contra las que pueden correr los tests: SQLite en memoria (la suite normal) y la
     * base PostgreSQL de prueba (tests/Postgres). RefreshDatabase las vacía.
     */
    public const BASES_PERMITIDAS = ['sqlite' => ':memory:', 'pgsql' => 'clinexa_test'];

    /**
     * Antes de que RefreshDatabase migre, se verifica a qué base apunta la conexión: si por un error
     * de configuración fuera la real, se borraría todo.
     */
    public function createApplication()
    {
        $this->aislarVistasCompiladas();

        $app = parent::createApplication();

        $this->configurarBaseDePrueba($app);

        $conexion = $app['config']->get('database.default');
        $base = $app['config']->get("database.connections.{$conexion}.database");

        if ((self::BASES_PERMITIDAS[$conexion] ?? null) !== $base) {
            throw new RuntimeException("Los tests no pueden correr contra la base '{$base}' (conexión {$conexion}). Revise phpunit.xml.");
        }

        return $app;
    }

    /**
     * Cada proceso de la corrida (el token de paratest; "serie" si no hay) compila las vistas en su propia
     * carpeta. Sin esto, todos comparten storage/framework/views y dos procesos (o el servidor de desarrollo)
     * pisan el mismo archivo: "rename(...): Acceso denegado" en Windows.
     */
    private function aislarVistasCompiladas(): void
    {
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        $carpeta = dirname(__DIR__).'/storage/framework/testing/views/'.(($token !== false && $token !== '' && $token !== null) ? 'proceso-'.$token : 'serie');

        if (! is_dir($carpeta)) {
            @mkdir($carpeta, 0777, true);
        }

        putenv('VIEW_COMPILED_PATH='.$carpeta);
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = $carpeta;
    }

    /** Punto de extensión para que una clase de tests use otra conexión (ver PostgresTestCase). */
    protected function configurarBaseDePrueba(Application $app): void {}
}
