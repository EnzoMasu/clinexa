<?php

use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\GeografiaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ABM de geografía (docs/geografia.md):
 * - departamentos.clave_origen y ciudades.clave_origen: identidad estable de lo que vino del seeder, para que renombrar
 *   desde la pantalla no haga que el seeder cree un duplicado con el nombre original. Los cargados a mano quedan en null.
 *   Se completa a los ya cargados que coinciden por nombre con los datos del seeder (sin tocar nombre ni estado).
 * - Gerencia pasa de VER a VER, CREAR, EDITAR y DESACTIVAR sobre GEOGRAFIA (solo suma).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['departamentos', 'ciudades'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->string('clave_origen', 200)->nullable()->unique();
            });
        }

        $paraguay = DB::table('paises')->where('codigo', 'PY')->value('id');
        if ($paraguay) {
            foreach ([...GeografiaSeeder::DEPARTAMENTOS, GeografiaSeeder::DISTRITO_CAPITAL] as $nombre) {
                DB::table('departamentos')->where('pais_id', $paraguay)->where('nombre', $nombre)
                    ->update(['clave_origen' => GeografiaSeeder::claveDepartamento($nombre)]);
            }
            foreach (require database_path('data/distritos-paraguay.php') as $departamento => $ciudades) {
                $departamentoId = DB::table('departamentos')->where('pais_id', $paraguay)->where('nombre', $departamento)->value('id');
                if ($departamentoId) {
                    foreach ($ciudades as $ciudad) {
                        DB::table('ciudades')->where('departamento_id', $departamentoId)->where('nombre', $ciudad)
                            ->update(['clave_origen' => GeografiaSeeder::claveCiudad($departamento, $ciudad)]);
                    }
                }
            }
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists() && ModuloSistema::where('codigo', 'GEOGRAFIA')->exists()) {
            PerfilesPredefinidos::sumarModulo('GEOGRAFIA', ['GERENCIA' => ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR']]);
        }
    }

    public function down(): void
    {
        foreach (['departamentos', 'ciudades'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropUnique(['clave_origen']);
                $table->dropColumn('clave_origen');
            });
        }
    }
};
