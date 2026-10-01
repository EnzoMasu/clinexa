<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El nombre de las sucursales y de los medios de pago pasa a ser único (dos con el mismo nombre
 * serían indistinguibles en los listados y selectores). Si ya hubiera repetidos, la migración se
 * detiene y los lista, para corregirlos a mano.
 */
return new class extends Migration
{
    private const TABLAS = ['sucursales', 'medios_pago'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            $repetidos = DB::table($tabla)->select('nombre')->groupBy('nombre')->havingRaw('count(*) > 1')->pluck('nombre');
            if ($repetidos->isNotEmpty()) {
                throw new RuntimeException("Hay nombres repetidos en {$tabla}: ".$repetidos->join(', ').'. Corríjalos antes de migrar.');
            }
        }

        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->unique('nombre');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropUnique(['nombre']);
            });
        }
    }
};
