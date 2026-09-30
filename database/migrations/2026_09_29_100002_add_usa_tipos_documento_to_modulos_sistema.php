<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * modulos_sistema.usa_tipos_documento marca los módulos que ofrecen tipos de documento: son los
 * que aparecen en el formulario de Tipos de documento para habilitarlos (tipo_documento_modulo).
 * Se marca en true a los módulos que ya tienen tipos habilitados (hoy, Personas).
 *
 * Además, como mucho un predeterminado por módulo: índice único parcial sobre las filas con
 * es_predeterminado (PostgreSQL y SQLite lo soportan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modulos_sistema', function (Blueprint $table) {
            $table->boolean('usa_tipos_documento')->default(false);
        });

        DB::table('modulos_sistema')
            ->whereIn('id', DB::table('tipo_documento_modulo')->select('modulo_sistema_id'))
            ->update(['usa_tipos_documento' => true]);

        DB::statement('CREATE UNIQUE INDEX tipo_documento_modulo_un_predeterminado ON tipo_documento_modulo (modulo_sistema_id) WHERE es_predeterminado = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX tipo_documento_modulo_un_predeterminado');

        Schema::table('modulos_sistema', function (Blueprint $table) {
            $table->dropColumn('usa_tipos_documento');
        });
    }
};
