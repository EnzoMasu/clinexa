<?php

use Database\Seeders\GeografiaSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Completa la clave de origen del Distrito Capital y de la ciudad de Asunción en bases cargadas cuando el departamento
 * se llamaba "Asunción (Capital)" (la migración anterior emparejaba por el nombre actual del seeder y no lo reconoció).
 * Repetible: solo toca filas con clave vacía y no hace nada si ya existe otra con esa clave o si no encuentra el registro.
 * No cambia nombre, estado ni ninguna otra fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        $paraguay = DB::table('paises')->where('codigo', 'PY')->value('id');
        if (! $paraguay) {
            return;
        }

        $nombreActual = GeografiaSeeder::DISTRITO_CAPITAL;
        $claveDepartamento = GeografiaSeeder::claveDepartamento($nombreActual);

        if (DB::table('departamentos')->where('clave_origen', $claveDepartamento)->exists()) {
            return;
        }

        $departamentos = DB::table('departamentos')->where('pais_id', $paraguay)->whereNull('clave_origen')
            ->whereIn('nombre', ['Asunción (Capital)', $nombreActual])->pluck('id');
        if ($departamentos->count() !== 1) {
            return; // no está, o es ambiguo: no se adivina
        }
        $departamentoId = $departamentos->first();

        DB::table('departamentos')->where('id', $departamentoId)->update(['clave_origen' => $claveDepartamento]);

        $claveCiudad = GeografiaSeeder::claveCiudad($nombreActual, 'Asunción');
        if (! DB::table('ciudades')->where('clave_origen', $claveCiudad)->exists()) {
            DB::table('ciudades')->where('departamento_id', $departamentoId)->where('nombre', 'Asunción')
                ->whereNull('clave_origen')->update(['clave_origen' => $claveCiudad]);
        }
    }

    public function down(): void
    {
        // Solo completa datos: no hay nada que deshacer sin perder la clave de origen.
    }
};
