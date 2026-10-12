<?php

namespace Database\Seeders;

use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Seeder;

/**
 * Perfiles predefinidos de la clínica (Gerencia, Médico, Enfermería, Recepción, ...) con su matriz inicial,
 * que está en App\Support\PerfilesPredefinidos. Idempotente: crea los que falten (o adopta el existente sin
 * código, como "Recepcionista" para RECEPCION) y NUNCA cambia los permisos de un perfil que ya existe.
 * Va después de ModuloSistemaSeeder y PerfilAdministradorSeeder. Ver docs/perfiles.md.
 */
class PerfilesPredefinidosSeeder extends Seeder
{
    public function run(): void
    {
        PerfilesPredefinidos::asegurar();
    }
}
