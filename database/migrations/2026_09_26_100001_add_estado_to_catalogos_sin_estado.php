<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los catálogos que no tenían estado pasan a tenerlo, con la misma baja lógica (INACTIVO) que el resto.
 *
 * Con un DEFAULT constante, PostgreSQL (11+) agrega la columna sin reescribir la tabla: las filas
 * existentes (12.436 en catalogo_cie10) quedan en ACTIVO al instante, sin un UPDATE masivo.
 */
return new class extends Migration
{
    private const TABLAS = ['especialidades', 'medios_pago', 'categorias_gasto', 'catalogo_cie10'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->enum('estado', ['ACTIVO', 'INACTIVO'])->default('ACTIVO');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('estado');
            });
        }
    }
};
