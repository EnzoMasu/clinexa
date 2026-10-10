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

    /**
     * Estos tests corren SOLO en serie. En paralelo (pest --parallel), Laravel crearía una base por proceso
     * (clinexa_test_test_N) que queda suelta en el servidor: se frena antes de que eso pase. El comando
     * rápido (docs/pruebas.md) no incluye tests/Postgres.
     */
    protected function setUp(): void
    {
        if (($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN')) !== false && ($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN')) !== '') {
            $this->fail('Los tests de PostgreSQL (tests/Postgres) corren solo en serie: use el comando completo de docs/pruebas.md.');
        }

        parent::setUp();
    }

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
