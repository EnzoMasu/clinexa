<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Informes de estudio (docs/informes-estudio.md): el informe de un estudio hecho en la clínica, escrito por el profesional
 * dentro de la consulta. Es parte de la consulta (regla de cierre, autoguardado, correcciones). Texto libre; el estudio se
 * elige del catálogo (estudio_id) o se escribe a mano, y su nombre se COPIA en estudio_nombre (cambiar el catálogo no
 * altera lo guardado). No se borra: se retira (activo = false). Solo esquema: no lee ni cambia filas, y no depende de
 * ningún dato de catálogo (sin módulos ni permisos nuevos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('informes_estudio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas');
            $table->foreignId('estudio_id')->nullable()->constrained('estudios');
            $table->string('estudio_nombre', 150);
            $table->string('solicitante', 150)->nullable();
            $table->text('texto');
            $table->integer('orden');
            $table->boolean('activo')->default(true);
            $table->foreignId('usuario_id')->nullable()->constrained('users');
            $table->foreignId('modificado_por_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['consulta_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informes_estudio');
    }
};
