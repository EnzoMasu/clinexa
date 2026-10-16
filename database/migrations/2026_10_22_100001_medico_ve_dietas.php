<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Migrations\Migration;

/**
 * El Médico puede listar las dietas (VER sobre DIETAS) para usarlas en "Insertar dieta"; no las administra. Solo SUMA el
 * permiso (idempotente) y solo si el módulo y el perfil existen: contra un catálogo sin sus datos no hace nada. No cambia
 * tablas: el texto de la dieta y el de la indicación ya son `text`; el tope de 3000 es de validación.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (ModuloSistema::where('codigo', 'DIETAS')->exists() && PerfilAcceso::where('codigo', PerfilesPredefinidos::MEDICO)->exists()) {
            PerfilesPredefinidos::sumarModulo('DIETAS', [PerfilesPredefinidos::MEDICO => ['VER']]);
        }
    }

    public function down(): void
    {
        // El permiso queda: los perfiles lo pueden tener asignado.
    }
};
