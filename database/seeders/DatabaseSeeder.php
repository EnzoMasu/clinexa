<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * No crea usuarios: el primer administrador se crea con `php artisan clinexa:crear-admin`.
     */
    public function run(): void
    {
        $this->call([
            ModuloSistemaSeeder::class,
            PerfilAdministradorSeeder::class,
            DatosRealesClinicaSeeder::class,
            CatalogoCie10Seeder::class,
        ]);

        // Personas ficticias para demo y pruebas: nunca en producción.
        if (! app()->isProduction()) {
            $this->call(PersonasDemoSeeder::class);
        }
    }
}
