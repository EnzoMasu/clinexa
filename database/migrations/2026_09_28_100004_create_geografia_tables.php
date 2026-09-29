<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geografía: País 1-N Departamento 1-N Ciudad, cada uno con estado (módulo GEOGRAFIA).
 * personas y sucursales suman ciudad_id opcional: es un dato adicional, la dirección en
 * texto libre se mantiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paises', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('departamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pais_id')->constrained('paises');
            $table->string('nombre', 100);
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();

            $table->unique(['pais_id', 'nombre']);
        });

        Schema::create('ciudades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departamento_id')->constrained('departamentos');
            $table->string('nombre', 100);
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();

            $table->unique(['departamento_id', 'nombre']);
        });

        foreach (['personas', 'sucursales'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('ciudad_id')->nullable()->constrained('ciudades')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['personas', 'sucursales'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropConstrainedForeignId('ciudad_id');
            });
        }

        Schema::dropIfExists('ciudades');
        Schema::dropIfExists('departamentos');
        Schema::dropIfExists('paises');
    }
};
