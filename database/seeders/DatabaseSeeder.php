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
            // Los medios de pago de referencia (Efectivo, Transferencia, Tarjeta): los usa la caja.
            MediosPagoSeeder::class,
            // Los motivos de anulación de factura de referencia (docs/facturacion.md).
            MotivosAnulacionFacturaSeeder::class,
            ModulosSensiblesSeeder::class,
            PerfilAdministradorSeeder::class,
            PerfilesPredefinidosSeeder::class,
            GeografiaSeeder::class,
            DatosRealesClinicaSeeder::class,
            CatalogoCie10Seeder::class,
        ]);

        // Personas ficticias para demo y pruebas: nunca en producción.
        if (! app()->isProduction()) {
            $this->call(PersonasDemoSeeder::class);
        }
    }
}
