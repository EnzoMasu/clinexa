<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disponibilidad semanal de un profesional en un consultorio: en qué día de la semana, de qué hora
 * a qué hora, con turnos de cuántos minutos y en qué período de vigencia. De acá salen los
 * horarios que se ofrecen al dar un turno (App\Support\Agenda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disponibilidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profesional_id')->constrained('profesionales');
            $table->foreignId('consultorio_id')->constrained('consultorios');
            $table->enum('dia_semana', ['LUN', 'MAR', 'MIE', 'JUE', 'VIE', 'SAB', 'DOM']);
            $table->time('hora_desde');
            $table->time('hora_hasta');
            $table->integer('duracion_turno_minutos');
            $table->date('vigencia_desde');
            $table->date('vigencia_hasta')->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();

            $table->index(['profesional_id', 'dia_semana']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilidades');
    }
};
