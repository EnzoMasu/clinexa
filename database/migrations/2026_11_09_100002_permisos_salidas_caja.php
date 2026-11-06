<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Migrations\Migration;

/**
 * Salidas de caja (docs/cobros-caja.md): Caja y Gerencia reciben VER, CREAR (registrar) y DESACTIVAR (anular o solicitar la anulación)
 * sobre SALIDAS_CAJA; el Administrador ya tiene todo. Solo SUMA: no quita ni cambia ningún otro permiso, y se puede correr más de una
 * vez. En una instalación nueva (sin perfiles ni el módulo) no hace nada: lo dan el seeder y la matriz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists() && ModuloSistema::where('codigo', 'SALIDAS_CAJA')->exists()) {
            PerfilesPredefinidos::sumarModulo('SALIDAS_CAJA', PerfilesPredefinidos::PERMISOS_SALIDAS_CAJA);
        }
    }

    public function down(): void
    {
        // Solo suma permisos: no se quitan (podrían haberse dado también desde la pantalla de perfiles).
    }
};
