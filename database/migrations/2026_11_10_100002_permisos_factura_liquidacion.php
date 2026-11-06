<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Migrations\Migration;

/**
 * La factura de la liquidación (docs/facturacion.md): Facturación y convenios recibe VER, CREAR (emitir) y DESACTIVAR (solicitar la
 * anulación) sobre FACTURAS_LIQUIDACION; el Administrador ya tiene todo. Solo SUMA: no quita ni cambia ningún otro permiso (tampoco
 * los de Caja ni Gerencia), y se puede correr más de una vez. En una instalación nueva (sin perfiles ni el módulo) no hace nada: lo
 * dan el seeder y la matriz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists() && ModuloSistema::where('codigo', 'FACTURAS_LIQUIDACION')->exists()) {
            PerfilesPredefinidos::sumarModulo('FACTURAS_LIQUIDACION', PerfilesPredefinidos::PERMISOS_FACTURAS_LIQUIDACION);
        }
    }

    public function down(): void
    {
        // Solo suma permisos: no se quitan (podrían haberse dado también desde la pantalla de perfiles).
    }
};
