<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Migrations\Migration;

/**
 * Categorías de gasto (docs/compras-gastos.md): Compras y tesorería y Gerencia pasan de VER a VER, CREAR, EDITAR y DESACTIVAR
 * sobre CATEGORIAS_GASTO (el Administrador ya tiene todo). Solo SUMA: no quita ni cambia ningún otro permiso, y se puede correr
 * más de una vez. No crea categorías: cada clínica carga las suyas.
 */
return new class extends Migration
{
    public const PERMISOS = [
        'COMPRAS_TESORERIA' => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'],
        'GERENCIA' => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR'],
    ];

    public function up(): void
    {
        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists() && ModuloSistema::where('codigo', 'CATEGORIAS_GASTO')->exists()) {
            PerfilesPredefinidos::sumarModulo('CATEGORIAS_GASTO', self::PERMISOS);
        }
    }

    public function down(): void
    {
        // Solo suma permisos: no se quitan (podrían haberse dado también desde la pantalla de perfiles).
    }
};
