<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de tipos de red social (Facebook, Instagram, WhatsApp, ...), para las redes de los
 * proveedores. Los tipos base los siembra DatosRealesClinicaSeeder si la tabla está vacía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_red_social', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_red_social');
    }
};
