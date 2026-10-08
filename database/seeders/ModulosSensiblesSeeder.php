<?php

namespace Database\Seeders;

use App\Models\ModuloSistema;
use Illuminate\Database\Seeder;

/**
 * Módulos sensibles (modulos_sistema.es_sensible): en ellos la auditoría registra también las
 * lecturas (VER), no solo los cambios. AUDITORIA incluida: queda registrado quién consulta el log.
 *
 * Solo aditivo: marca los de la lista que falten y nunca desmarca, así no pisa un módulo que se
 * haya marcado como sensible a mano.
 */
class ModulosSensiblesSeeder extends Seeder
{
    public const SENSIBLES = ['USUARIOS', 'PERFILES_ACCESO', 'PERSONAS', 'PACIENTES', 'AUDITORIA', 'HISTORIA_CLINICA'];

    public function run(): void
    {
        ModuloSistema::whereIn('codigo', self::SENSIBLES)->where('es_sensible', false)->update(['es_sensible' => true]);
    }
}
