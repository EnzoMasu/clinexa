<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * paises.codigo: código ISO 3166-1 alfa-2 (PY, BR, AR), único y obligatorio. A los países ya
 * cargados se les completa desde database/data/paises.php (de donde salieron); si alguno no está
 * en esa lista (cargado a mano con otro nombre), la migración se detiene y los lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paises', function (Blueprint $table) {
            $table->string('codigo', 2)->nullable()->after('id');
        });

        $codigos = array_flip(require database_path('data/paises.php')); // nombre => código
        $sinCodigo = [];

        foreach (DB::table('paises')->get(['id', 'nombre']) as $pais) {
            if (! isset($codigos[$pais->nombre])) {
                $sinCodigo[] = $pais->nombre;

                continue;
            }
            DB::table('paises')->where('id', $pais->id)->update(['codigo' => $codigos[$pais->nombre]]);
        }

        if ($sinCodigo) {
            throw new RuntimeException('Países sin código ISO conocido: '.implode(', ', $sinCodigo).'. Asígneles el código a mano y vuelva a migrar.');
        }

        Schema::table('paises', function (Blueprint $table) {
            $table->string('codigo', 2)->nullable(false)->change();
            $table->unique('codigo');
        });
    }

    public function down(): void
    {
        Schema::table('paises', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });
    }
};
