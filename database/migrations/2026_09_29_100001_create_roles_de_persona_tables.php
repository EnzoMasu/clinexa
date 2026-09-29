<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles de negocio sobre Persona. Cada rol referencia una persona existente (persona_id UNIQUE:
 * una persona tiene como máximo un registro de cada rol, pero puede tener varios roles distintos).
 * Los datos personales (nombre, documento, contacto) no se repiten: están en personas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas');
            $table->string('nro_ficha', 20)->unique();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('profesionales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas');
            $table->string('matricula', 50)->unique();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas');
            $table->text('condiciones_comerciales')->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('propietarios_equipo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas');
            $table->string('datos_bancarios', 255)->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('responsables_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas');
            $table->decimal('limite_credito', 12, 2)->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        Schema::create('profesional_especialidad', function (Blueprint $table) {
            $table->foreignId('profesional_id')->constrained('profesionales')->cascadeOnDelete();
            $table->foreignId('especialidad_id')->constrained('especialidades');
            $table->string('nro_matricula_especialidad', 50)->nullable();
            $table->date('fecha_desde');

            $table->primary(['profesional_id', 'especialidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profesional_especialidad');
        Schema::dropIfExists('responsables_pago');
        Schema::dropIfExists('propietarios_equipo');
        Schema::dropIfExists('proveedores');
        Schema::dropIfExists('profesionales');
        Schema::dropIfExists('pacientes');
    }
};
