<?php

use Database\Seeders\TiposComprobanteCompraSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compras y gastos (docs/compras-gastos.md), 1 de 2: el catálogo editable de tipos de comprobante de compra, con sus casillas de reglas
 * (requiere número, número con formato legal, requiere timbrado, requiere fecha del comprobante, discrimina IVA) y su estado
 * (ACTIVO/INACTIVO, módulo TIPOS_COMPROBANTE_COMPRA). Siembra los cuatro iniciales: factura legal, ticket, recibo y sin comprobante.
 * Aditiva; nada se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_comprobante_compra', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            // Sin número: la compra no lleva número y queda fuera del control de duplicados.
            $table->boolean('requiere_numero')->default(false);
            // 000-000-0000000 (solo con requiere_numero).
            $table->boolean('numero_formato_legal')->default(false);
            $table->boolean('requiere_timbrado')->default(false);
            $table->boolean('requiere_fecha_comprobante')->default(false);
            // Solo la factura legal: la compra se carga por tasa (10 %, 5 % y exento). Si no, solo el total, sin IVA.
            $table->boolean('discrimina_iva')->default(false);
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tipos_comprobante_compra ADD CONSTRAINT tipos_comprobante_compra_formato_con_numero CHECK (requiere_numero OR NOT numero_formato_legal)');
        }

        (new TiposComprobanteCompraSeeder)->run();
    }

    /** Solo sin compras (la de compras se revierte antes). */
    public function down(): void
    {
        Schema::dropIfExists('tipos_comprobante_compra');
    }
};
