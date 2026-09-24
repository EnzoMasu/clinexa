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
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo_persona', ['FISICA', 'JURIDICA']);
            $table->foreignId('tipo_documento_id')->constrained('tipos_documento');
            $table->string('nro_documento', 20);
            $table->string('apellidos', 100)->nullable();
            $table->string('nombres', 100)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->enum('sexo', ['M', 'F', 'OTRO'])->nullable();
            $table->string('nacionalidad', 50)->nullable();
            $table->enum('estado_civil', ['SOLTERO', 'CASADO', 'VIUDO', 'UNIDO', 'SEPARADO', 'DIVORCIADO'])->nullable();
            $table->string('razon_social', 150)->nullable();
            $table->string('nombre_fantasia', 150)->nullable();
            $table->string('representante_legal', 150)->nullable();
            $table->string('email', 100);
            $table->string('telefono', 20);
            $table->string('direccion', 200);
            $table->enum('estado', ['ACTIVO', 'INACTIVO'])->default('ACTIVO');
            $table->timestamps();

            $table->unique(['tipo_documento_id', 'nro_documento']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
