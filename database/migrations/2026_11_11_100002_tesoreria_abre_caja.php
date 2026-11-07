<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Migrations\Migration;

/**
 * Compras y tesorería abre su propia caja para los pagos grandes en efectivo a los beneficiarios (docs/liquidacion-beneficiarios.md):
 * CAJAS (VER, CREAR, EDITAR: abrir y cerrar la propia) y SALIDAS_CAJA (VER, CREAR, DESACTIVAR: en su caja). Sin COBROS ni CIERRES_CAJA:
 * no cobra, no ve cajas ajenas ni recibe arqueos; sus cajas pasan por la recepción de Gerencia como las demás. Solo SUMA, se puede
 * correr más de una vez y en una instalación nueva no hace nada (lo dan el seeder y la matriz).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            return;
        }
        foreach (PerfilesPredefinidos::PERMISOS_TESORERIA_CAJA as $modulo => $accionesPorPerfil) {
            if (ModuloSistema::where('codigo', $modulo)->exists()) {
                PerfilesPredefinidos::sumarModulo($modulo, $accionesPorPerfil);
            }
        }
    }

    public function down(): void
    {
        // Solo suma permisos: no se quitan (podrían haberse dado también desde la pantalla de perfiles).
    }
};
