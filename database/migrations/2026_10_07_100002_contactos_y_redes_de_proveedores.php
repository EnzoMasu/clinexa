<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de contacto de los proveedores (empresa):
 * - proveedores.sitio_web.
 * - contactos_proveedor: lista simple de personas de contacto (no son Personas del sistema). No se
 *   borran: se deshabilitan (activo = false).
 * - redes_sociales_proveedor: redes de la empresa, con su tipo (catálogo tipos_red_social). Son un
 *   dato descriptivo: se pueden quitar (el valor anterior queda en la auditoría).
 * Sin backfill: los proveedores existentes quedan sin estos datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('sitio_web', 255)->nullable()->after('datos_bancarios');
        });

        Schema::create('contactos_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->string('nombre', 100);
            $table->string('apellido', 100);
            $table->string('telefono', 20)->nullable();
            $table->string('correo', 100)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['proveedor_id', 'activo']);
        });

        Schema::create('redes_sociales_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('tipo_red_social_id')->constrained('tipos_red_social');
            $table->string('enlace', 255);
            $table->timestamps();

            $table->index('proveedor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redes_sociales_proveedor');
        Schema::dropIfExists('contactos_proveedor');

        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('sitio_web');
        });
    }
};
