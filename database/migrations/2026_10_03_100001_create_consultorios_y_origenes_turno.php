<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos de la agenda: consultorios (cada uno en una sucursal) y orígenes de turno
 * (PRESENCIAL, TELEFONICO, WEB, APP: los siembra DatosRealesClinicaSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultorios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursal_id')->constrained('sucursales');
            $table->string('nombre', 100);
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();

            $table->unique(['sucursal_id', 'nombre']);
        });

        Schema::create('origenes_turno', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 50);
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('origenes_turno');
        Schema::dropIfExists('consultorios');
    }
};
