<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías de proveedor (catálogo, varias por proveedor) y datos bancarios del proveedor (lo que
 * tenía el rol PropietarioEquipo, que se fusiona en Proveedor). Las categorías base las siembra
 * DatosRealesClinicaSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_proveedor', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('proveedor_categoria', function (Blueprint $table) {
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->foreignId('categoria_proveedor_id')->constrained('categorias_proveedor');

            $table->primary(['proveedor_id', 'categoria_proveedor_id']);
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('datos_bancarios', 255)->nullable()->after('condiciones_comerciales');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('datos_bancarios');
        });

        Schema::dropIfExists('proveedor_categoria');
        Schema::dropIfExists('categorias_proveedor');
    }
};
