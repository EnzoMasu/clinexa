<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historia clínica, tanda 2: indicaciones generales de la consulta y recetas.
 *
 * - indicaciones: reposo, dieta, control... No se borran: se retiran (activo = false).
 * - recetas: borrador (PENDIENTE), emitida (EMITIDO) o anulada (ANULADO). El número (RE-0000001), la
 *   fecha y el snapshot (todo lo que se imprime) se completan al emitir; desde ahí no se modifica.
 * - detalles_receta: los renglones de medicamentos, en texto libre (no hay catálogo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas');
            $table->foreignId('tipo_indicacion_id')->nullable()->constrained('tipos_indicacion');
            $table->text('descripcion');
            $table->integer('orden');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['consulta_id', 'orden']);
        });

        Schema::create('recetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas');
            // Se asigna al emitir: los borradores no consumen números.
            $table->string('numero', 20)->nullable()->unique();
            $table->date('fecha')->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamp('emitida_en')->nullable();
            $table->timestamp('anulada_en')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->foreignId('reemplaza_a_id')->nullable()->constrained('recetas');
            // Lo que se imprimió, tal cual: la hoja de una receta emitida se dibuja siempre desde acá.
            $table->jsonb('snapshot')->nullable();
            // Con microsegundos: updated_at es el control de concurrencia del borrador.
            $table->timestamps(6);

            $table->index('consulta_id');
        });

        Schema::create('detalles_receta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receta_id')->constrained('recetas');
            $table->string('medicamento', 150);
            $table->string('cantidad', 100)->nullable();
            $table->string('dosis', 100);
            $table->string('via', 50)->nullable();
            $table->string('frecuencia', 100);
            $table->string('duracion', 100)->nullable();
            $table->string('observaciones', 200)->nullable();
            $table->integer('orden');
            $table->timestamps();

            $table->index(['receta_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalles_receta');
        Schema::dropIfExists('recetas');
        Schema::dropIfExists('indicaciones');
    }
};
