<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Tests que necesitan PostgreSQL de verdad (tests/Postgres): lo que SQLite no tiene, como las
 * restricciones EXCLUDE de los turnos. Corren contra la base de prueba clinexa_test (host,
 * usuario y contraseña del .env), nunca contra la real. El resto de la suite sigue en SQLite en
 * memoria, que es mucho más rápido.
 */
abstract class PostgresTestCase extends TestCase
{
    use RefreshDatabase;

    /** Migrada una vez por corrida (RefreshDatabase lleva un único "ya migré" compartido con SQLite). */
    private static bool $migrada = false;

    protected function configurarBaseDePrueba(Application $app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.database', self::BASES_PERMITIDAS['pgsql']);
        $app['db']->purge('pgsql');
    }

    protected function refreshTestDatabase()
    {
        if (! self::$migrada) {
            $this->artisan('migrate:fresh', ['--database' => 'pgsql']);
            $this->app[Kernel::class]->setArtisan(null);
            self::$migrada = true;
        }

        $this->beginDatabaseTransaction();
    }
}
