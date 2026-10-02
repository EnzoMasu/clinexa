<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos: un paciente con un profesional, en un consultorio, una fecha y un horario. Estados:
 * PENDIENTE (inicial), CONFIRMADO, ATENDIDO, CANCELADO, AUSENTE. La no superposición la garantiza
 * la base (migración siguiente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes');
            $table->foreignId('profesional_id')->constrained('profesionales');
            $table->foreignId('consultorio_id')->constrained('consultorios');
            $table->foreignId('procedimiento_id')->nullable()->constrained('procedimientos');
            // FK a equipos se agrega cuando exista ese módulo. (PostgreSQL no tiene BIGINT UNSIGNED:
            // es un bigint común, igual que las demás claves.)
            $table->bigInteger('equipo_id')->nullable();
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('origen_turno_id')->nullable()->constrained('origenes_turno');
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['profesional_id', 'fecha']);
            $table->index(['consultorio_id', 'fecha']);
            $table->index(['paciente_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turnos');
    }
};
