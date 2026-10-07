<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log de auditoría, de solo agregar (App\Models\LogAuditoria lo impide en la aplicación y, en
 * PostgreSQL, un trigger en la base). El historial empieza con esta migración.
 *
 * - usuario_id: nulo cuando no hay usuario (intento de inicio de sesión con un correo inexistente).
 * - registro_afectado_id: texto, porque catalogo_cie10 usa el código como clave; nulo cuando no hay
 *   un registro puntual (listado, intento de inicio de sesión sin usuario).
 * - fecha_hora: en UTC, como el resto de las fecha-hora; se muestra en hora de Paraguay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('users');
            $table->string('tabla_afectada', 100);
            $table->string('registro_afectado_id', 50)->nullable();
            $table->string('accion', 30);
            $table->jsonb('valor_anterior')->nullable();
            $table->jsonb('valor_nuevo')->nullable();
            $table->text('detalle')->nullable();
            $table->string('ip_origen', 45)->nullable();
            $table->timestamp('fecha_hora')->useCurrent();

            $table->index(['tabla_afectada', 'registro_afectado_id']);
            $table->index('usuario_id');
            $table->index('fecha_hora');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_auditoria');
    }
};
