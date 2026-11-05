<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Migrations\Migration;

/**
 * Compras (docs/compras-gastos.md): Gerencia suma CREAR y EDITAR sobre COMPRAS (ya tenía VER y DESACTIVAR). Solo SUMA: no quita ni
 * cambia ningún otro permiso, y se puede correr más de una vez. Nadie confirma lo que cargó: lo de Gerencia lo confirma el Administrador.
 */
return new class extends Migration
{
    public const PERMISOS = ['GERENCIA' => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR']];

    public function up(): void
    {
        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists() && ModuloSistema::where('codigo', 'COMPRAS')->exists()) {
            PerfilesPredefinidos::sumarModulo('COMPRAS', self::PERMISOS);
        }
    }

    public function down(): void
    {
        // Solo suma permisos: no se quitan (podrían haberse dado también desde la pantalla de perfiles).
    }
};
