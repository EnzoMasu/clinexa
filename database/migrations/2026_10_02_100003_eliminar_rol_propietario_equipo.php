<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paso 2 de la fusión: borra el rol PropietarioEquipo (tabla propietarios_equipo y módulo
 * PROPIETARIOS_EQUIPO, con sus permisos, asignaciones a perfiles y estados en cascada).
 *
 * Antes vuelve a verificar que cada propietario esté copiado como proveedor con "Equipos médicos"
 * (lo hizo la migración anterior); si falta alguno, se detiene sin borrar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('propietarios_equipo')) {
            $sinCopiar = DB::table('propietarios_equipo')->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('proveedores')
                ->join('proveedor_categoria', 'proveedor_categoria.proveedor_id', '=', 'proveedores.id')
                ->join('categorias_proveedor', 'categorias_proveedor.id', '=', 'proveedor_categoria.categoria_proveedor_id')
                ->whereColumn('proveedores.persona_id', 'propietarios_equipo.persona_id')
                ->where('categorias_proveedor.nombre', 'Equipos médicos'))
                ->pluck('persona_id');

            if ($sinCopiar->isNotEmpty()) {
                throw new RuntimeException('Propietarios de equipo sin copiar a proveedores (personas '.$sinCopiar->join(', ').'). No se borró nada.');
            }

            Schema::drop('propietarios_equipo');
        }

        // En cascada: permisos -> perfil_permiso, estado_modulo y tipo_documento_modulo del módulo.
        DB::table('modulos_sistema')->where('codigo', 'PROPIETARIOS_EQUIPO')->delete();
    }

    /** Recrea la tabla vacía (los datos quedaron en proveedores); el módulo lo recrea ModuloSistemaSeeder si se vuelve a agregar. */
    public function down(): void
    {
        Schema::create('propietarios_equipo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas');
            $table->string('datos_bancarios', 255)->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });
    }
};
