<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RUC propio de la persona (docs/ruc.md): campo aparte, único cuando no es nulo. La persona jurídica no tiene CI ni
 * pasaporte, así que el documento (tipo y número) pasa a ser opcional.
 *
 * Traslado de datos: quien hoy guarda su RUC como documento (tipo RUC) lo recibe también en el campo nuevo. El documento
 * viejo NO se toca. Sin el tipo RUC en el catálogo, no traslada nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->string('ruc', 20)->nullable()->after('nro_documento');
            $table->foreignId('tipo_documento_id')->nullable()->change();
            $table->string('nro_documento', 20)->nullable()->change();
        });

        DB::table('personas')
            ->whereNull('ruc')
            ->whereIn('tipo_documento_id', DB::table('tipos_documento')->where('codigo', 'RUC')->select('id'))
            ->update(['ruc' => DB::raw('nro_documento')]);

        DB::statement('CREATE UNIQUE INDEX personas_ruc_unico ON personas (ruc) WHERE ruc IS NOT NULL');
    }

    /** Quita el campo. El documento sigue siendo opcional: volver a exigirlo fallaría con las jurídicas sin documento. */
    public function down(): void
    {
        DB::statement('DROP INDEX personas_ruc_unico');

        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('ruc');
        });
    }
};
