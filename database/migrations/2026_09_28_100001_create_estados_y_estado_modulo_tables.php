<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo unificado de estados (reemplaza los enum "estado" de cada tabla) y la relación
 * módulo ↔ estados habilitados. Los 22 estados se siembran acá (no en un seeder) porque la
 * migración siguiente los necesita para convertir los enum existentes.
 */
return new class extends Migration
{
    private const ESTADOS = [
        'ACTIVO', 'INACTIVO', 'BLOQUEADO', 'PENDIENTE', 'CONFIRMADO', 'ATENDIDO', 'CANCELADO', 'AUSENTE',
        'REALIZADO', 'VIGENTE', 'VENCIDO', 'SUSPENDIDO', 'MANTENIMIENTO', 'FACTURADO', 'ANULADO', 'HISTORICO',
        'GENERADO', 'PAGADO', 'PARCIAL', 'EMITIDO', 'APROBADO', 'RECHAZADO',
    ];

    public function up(): void
    {
        Schema::create('estados', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 50);
            $table->timestamps();
        });

        $ahora = now();
        DB::table('estados')->insert(array_map(
            fn (string $codigo) => ['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => $ahora, 'updated_at' => $ahora],
            self::ESTADOS,
        ));

        Schema::create('estado_modulo', function (Blueprint $table) {
            $table->foreignId('modulo_sistema_id')->constrained('modulos_sistema')->cascadeOnDelete();
            $table->foreignId('estado_id')->constrained('estados');
            $table->boolean('es_inicial')->default(false);

            $table->primary(['modulo_sistema_id', 'estado_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estado_modulo');
        Schema::dropIfExists('estados');
    }
};
