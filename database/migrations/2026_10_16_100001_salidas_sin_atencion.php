<?php

use App\Models\Estado;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salidas sin atención (docs/retiros.md): el estado RETIRADO del turno, la tabla salidas_sin_atencion (qué pasó y quién lo
 * registró; sin contenido clínico) y notas_salida (la nota clínica del profesional, una sola vez; contenido clínico).
 * Aditiva: no cambia ni borra datos. RETIRADO se suma a los estados del módulo TURNOS sin tocar los demás. cargo_pendiente
 * es solo una marca: no hay monto, tarifa ni pagador (no existe Facturación).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('estados')->where('codigo', Estado::RETIRADO)->exists()) {
            DB::table('estados')->insert(['codigo' => Estado::RETIRADO, 'nombre' => 'RETIRADO', 'created_at' => now(), 'updated_at' => now()]);
        }

        // Solo en una instalación existente (con módulos): en una nueva lo hace ModuloSistemaSeeder.
        $modulo = DB::table('modulos_sistema')->where('codigo', 'TURNOS')->value('id');
        $estado = DB::table('estados')->where('codigo', Estado::RETIRADO)->value('id');
        if ($modulo && ! DB::table('estado_modulo')->where('modulo_sistema_id', $modulo)->where('estado_id', $estado)->exists()) {
            DB::table('estado_modulo')->insert(['modulo_sistema_id' => $modulo, 'estado_id' => $estado, 'es_inicial' => false]);
        }

        Schema::create('salidas_sin_atencion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_id')->unique()->constrained('turnos');
            $table->string('tipo', 10);          // RETIRO | RECHAZO
            $table->string('origen', 20);        // RECEPCION | CIERRE_JORNADA
            $table->boolean('triaje_terminado');
            $table->boolean('cargo_pendiente');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('notas_salida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_id')->unique()->constrained('salidas_sin_atencion');
            $table->text('texto');
            $table->foreignId('profesional_id')->constrained('profesionales');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_salida');
        Schema::dropIfExists('salidas_sin_atencion');
        // El estado RETIRADO queda en el catálogo (los turnos retirados lo usan).
    }
};
