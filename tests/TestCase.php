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
        $app = parent::createApplication();

        $this->configurarBaseDePrueba($app);

        $conexion = $app['config']->get('database.default');
        $base = $app['config']->get("database.connections.{$conexion}.database");

        if ((self::BASES_PERMITIDAS[$conexion] ?? null) !== $base) {
            throw new RuntimeException("Los tests no pueden correr contra la base '{$base}' (conexión {$conexion}). Revise phpunit.xml.");
        }

        return $app;
    }

    /** Punto de extensión para que una clase de tests use otra conexión (ver PostgresTestCase). */
    protected function configurarBaseDePrueba(Application $app): void {}
}
