<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué tipos de documento acepta cada módulo pasa a definirse en tipo_documento_modulo
 * (reemplaza a tipos_documento.aplica_a, que se elimina).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_documento', function (Blueprint $table) {
            $table->dropColumn('aplica_a');
        });

        Schema::create('tipo_documento_modulo', function (Blueprint $table) {
            $table->foreignId('modulo_sistema_id')->constrained('modulos_sistema')->cascadeOnDelete();
            $table->foreignId('tipo_documento_id')->constrained('tipos_documento')->cascadeOnDelete();
            $table->boolean('es_predeterminado')->default(false);

            $table->primary(['modulo_sistema_id', 'tipo_documento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_documento_modulo');

        Schema::table('tipos_documento', function (Blueprint $table) {
            $table->enum('aplica_a', ['FISICA', 'JURIDICA', 'AMBOS'])->default('AMBOS');
        });
    }
};
