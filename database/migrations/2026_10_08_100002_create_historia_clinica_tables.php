<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historia clínica: una por paciente, con sus consultas. Cada consulta tiene bloques de anamnesis,
 * un examen físico (como mucho uno) y diagnósticos CIE-10. Nada se borra: los bloques y los
 * diagnósticos se retiran (activo = false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historias_clinicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->unique()->constrained('pacientes');
            $table->date('fecha_apertura');
            $table->timestamps();
        });

        Schema::create('consultas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('historia_clinica_id')->constrained('historias_clinicas');
            // Un turno genera como máximo una consulta (también frena dos envíos simultáneos).
            $table->foreignId('turno_id')->nullable()->unique()->constrained('turnos');
            $table->foreignId('profesional_id')->constrained('profesionales');
            // Momento en que se guardó por primera vez; no se edita.
            $table->timestamp('fecha_hora');
            $table->text('motivo_consulta');
            // Con microsegundos: el control de concurrencia compara updated_at (dos guardados en el
            // mismo segundo no deben parecer iguales).
            $table->timestamps(6);

            $table->index(['historia_clinica_id', 'fecha_hora']);
            $table->index('profesional_id');
        });

        Schema::create('bloques_anamnesis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas');
            $table->foreignId('tipo_bloque_anamnesis_id')->constrained('tipos_bloque_anamnesis');
            $table->text('contenido');
            $table->integer('orden');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['consulta_id', 'orden']);
        });

        Schema::create('examenes_fisicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->unique()->constrained('consultas');
            $table->string('presion_arterial', 10)->nullable();
            $table->integer('frecuencia_cardiaca')->nullable();
            $table->integer('frecuencia_respiratoria')->nullable();
            $table->decimal('temperatura', 4, 1)->nullable();
            $table->decimal('peso', 5, 2)->nullable();
            $table->decimal('talla', 5, 1)->nullable();
            $table->integer('saturacion_oxigeno')->nullable();
            $table->text('hallazgos')->nullable();
            $table->timestamps();
        });

        Schema::create('diagnosticos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas');
            $table->string('codigo_cie10', 10);
            $table->foreign('codigo_cie10')->references('codigo')->on('catalogo_cie10');
            $table->enum('tipo', ['PRESUNTIVO', 'CONFIRMADO']);
            $table->boolean('principal')->default(false);
            $table->text('descripcion_adicional')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('consulta_id');
            $table->index('codigo_cie10');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosticos');
        Schema::dropIfExists('examenes_fisicos');
        Schema::dropIfExists('bloques_anamnesis');
        Schema::dropIfExists('consultas');
        Schema::dropIfExists('historias_clinicas');
    }
};
