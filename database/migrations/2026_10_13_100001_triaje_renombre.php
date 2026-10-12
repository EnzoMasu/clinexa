<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Preparación" pasa a llamarse "Triaje" en pantalla. Solo cambian los NOMBRES visibles: el del módulo
 * PREPARACION ("Triaje") y el del estado EN_PREPARACION ("EN TRIAJE"). Los códigos (PREPARACION,
 * EN_PREPARACION), las tablas, las columnas y las clases no se renombran: los usan los permisos de los perfiles,
 * la auditoría ya registrada y el código. Ver docs/triaje.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('modulos_sistema')->where('codigo', 'PREPARACION')->update(['nombre' => 'Triaje']);
        DB::table('estados')->where('codigo', 'EN_PREPARACION')->update(['nombre' => 'EN TRIAJE']);
    }

    public function down(): void
    {
        DB::table('modulos_sistema')->where('codigo', 'PREPARACION')->update(['nombre' => 'Preparación']);
        DB::table('estados')->where('codigo', 'EN_PREPARACION')->update(['nombre' => 'EN PREPARACIÓN']);
    }
};
