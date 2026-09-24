<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modulo_sistema_id')->constrained('modulos_sistema')->cascadeOnDelete();
            $table->enum('accion', ['VER', 'CREAR', 'EDITAR', 'DESACTIVAR', 'EXPORTAR']);
            $table->timestamps();

            $table->unique(['modulo_sistema_id', 'accion']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permisos');
    }
};
