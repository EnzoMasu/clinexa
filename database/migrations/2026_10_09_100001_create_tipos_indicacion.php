<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de tipos de indicación (Reposo, Dieta, Control, General),
 * para las indicaciones generales de las consultas. Los tipos base los siembra DatosRealesClinicaSeeder si la
 * tabla está vacía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_indicacion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_indicacion');
    }
};
