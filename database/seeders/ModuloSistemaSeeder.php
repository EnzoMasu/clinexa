<?php

namespace Database\Seeders;

use App\Models\ModuloSistema;
use Illuminate\Database\Seeder;

/**
 * Módulos del sistema (filas de la matriz de permisos). Idempotente: se puede correr
 * de nuevo cuando se agreguen módulos, actualiza por código sin duplicar.
 */
class ModuloSistemaSeeder extends Seeder
{
    public function run(): void
    {
        $modulos = [
            'USUARIOS' => 'Usuarios',
            'PERFILES_ACCESO' => 'Perfiles de acceso',
            'PERSONAS' => 'Personas',
            'ESPECIALIDADES' => 'Especialidades',
            'SUCURSALES' => 'Sucursales',
            'TIPOS_DOCUMENTO' => 'Tipos de documento',
            'CIE10' => 'Catálogo CIE-10',
            'MEDIOS_PAGO' => 'Medios de pago',
            'CATEGORIAS_GASTO' => 'Categorías de gasto',
            'PROCEDIMIENTOS' => 'Procedimientos',
        ];

        foreach ($modulos as $codigo => $nombre) {
            ModuloSistema::updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        }
    }
}
