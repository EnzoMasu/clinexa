<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recetas con indicación de uso (docs/pdf-clinico.md, "Receta en PDF"): cada renglón lleva un texto libre con cómo
 * usar el medicamento, obligatorio para emitir. Reemplaza a dosis, vía, frecuencia, duración y observaciones del
 * renglón en las recetas nuevas; esas columnas quedan (las recetas ya emitidas las tienen en su snapshot y en sus
 * renglones) y dosis y frecuencia pasan a admitir vacío. Solo esquema: no lee ni cambia filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalles_receta', function (Blueprint $table) {
            $table->text('indicacion_uso')->nullable();
            $table->string('dosis', 100)->nullable()->change();
            $table->string('frecuencia', 100)->nullable()->change();
        });
    }

    /** Dosis y frecuencia quedan admitiendo vacío: los renglones nuevos no las tienen. */
    public function down(): void
    {
        Schema::table('detalles_receta', function (Blueprint $table) {
            $table->dropColumn('indicacion_uso');
        });
    }
};
