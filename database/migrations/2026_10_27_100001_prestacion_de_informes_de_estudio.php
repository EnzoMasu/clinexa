<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prestación de cada informe de estudio (docs/tarifas-prestaciones.md, docs/informes-estudio.md).
 *
 * - `informes_estudio.procedimiento_id`: el procedimiento (de tipo ESTUDIO) del informe. Vacío en la base porque los informes
 *   anteriores conservan su nombre y quedan sin procedimiento; la aplicación lo pide en los nuevos.
 * - `prestaciones.informe_estudio_id`: la prestación de un informe. La única por consulta pasa a ser la BASE (sin informe):
 *   `prestaciones_base_unica` (consulta_id WHERE informe_estudio_id IS NULL) y `prestaciones_informe_unica` (informe_estudio_id
 *   WHERE IS NOT NULL), índices únicos parciales en SQLite y PostgreSQL. Las prestaciones existentes son todas base (una por
 *   consulta): cumplen las dos.
 * - Hito `PRESTACIONES_INFORMES`: los informes de consultas finalizadas antes nunca generan prestación (ni aparecen pendientes).
 *
 * Solo esquema y el hito: no lee filas (vale en `migrate --pretend`) ni depende de ningún dato de catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('informes_estudio', function (Blueprint $table) {
            $table->foreignId('procedimiento_id')->nullable()->after('estudio_id')->constrained('procedimientos');
        });

        Schema::table('prestaciones', function (Blueprint $table) {
            $table->foreignId('informe_estudio_id')->nullable()->after('turno_id')->constrained('informes_estudio');
        });
        Schema::table('prestaciones', function (Blueprint $table) {
            $table->dropUnique('prestaciones_consulta_id_unique');
            $table->index('consulta_id');
        });
        DB::statement('CREATE UNIQUE INDEX prestaciones_base_unica ON prestaciones (consulta_id) WHERE informe_estudio_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX prestaciones_informe_unica ON prestaciones (informe_estudio_id) WHERE informe_estudio_id IS NOT NULL');

        DB::table('hitos_sistema')->insert(['codigo' => 'PRESTACIONES_INFORMES', 'fecha_hora' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Solo sin prestaciones de informes (si no, la consulta tendría más de una y no vuelve a ser única). */
    public function down(): void
    {
        DB::table('hitos_sistema')->where('codigo', 'PRESTACIONES_INFORMES')->delete();
        DB::statement('DROP INDEX IF EXISTS prestaciones_informe_unica');
        DB::statement('DROP INDEX IF EXISTS prestaciones_base_unica');
        Schema::table('prestaciones', function (Blueprint $table) {
            $table->dropIndex(['consulta_id']);
            $table->unique('consulta_id');
        });
        Schema::table('prestaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('informe_estudio_id');
        });
        Schema::table('informes_estudio', function (Blueprint $table) {
            $table->dropConstrainedForeignId('procedimiento_id');
        });
    }
};
