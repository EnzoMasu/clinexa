<?php

namespace Database\Seeders;

use App\Models\PerfilAcceso;
use Illuminate\Database\Seeder;

/**
 * Perfil "Administrador" con las 5 acciones en todos los módulos. Idempotente: al agregar
 * módulos nuevos al ModuloSistemaSeeder, correrlo de nuevo le suma los permisos que falten.
 */
class PerfilAdministradorSeeder extends Seeder
{
    public function run(): void
    {
        PerfilAcceso::asegurarAdministrador();
    }
}
