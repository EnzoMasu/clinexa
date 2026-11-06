<?php

use App\Models\Estado;
use App\Models\ModuloSistema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salidas de caja (docs/cobros-caja.md): retiros, gastos y pagos menores en efectivo desde la caja abierta de hoy. Aditiva: no cambia
 * ningún cobro, caja, cierre ni movimiento ya cargado.
 *
 * - `salidas_caja`: la salida (categoría de gasto, beneficiario, comprobante opcional, concepto y monto en guaraníes enteros), VIGENTE
 *   o ANULADA (con quién, cuándo y el motivo). `compra_id` queda vacío: previsto para vincularla a una compra en una fase posterior.
 * - `movimientos_caja.salida_caja_id` y los tipos SALIDA (egreso en efectivo, en negativo) y ANULACION_SALIDA (el reingreso que la
 *   compensa, en positivo); a lo sumo uno de cada tipo por salida (`movimientos_caja_salida_por_tipo`). En PostgreSQL el CHECK
 *   `movimientos_caja_tipo_valido` suma los dos tipos; las filas existentes (APERTURA, COBRO, REVERSION) lo cumplen sin cambios.
 * - `solicitudes_reversion_cobro`: la misma solicitud sirve para anular una salida pasado el plazo (`salida_caja_id`, y `cobro_id`
 *   vacío). Exactamente uno de los dos (`solicitudes_reversion_objeto`, en PostgreSQL) y una sola pendiente por salida
 *   (`solicitudes_anulacion_salida_una_pendiente`). Las solicitudes existentes son todas de cobros y no cambian.
 *
 * El módulo SALIDAS_CAJA (estados VIGENTE y ANULADO), solo en una instalación existente (en una nueva lo crea ModuloSistemaSeeder). Los
 * permisos, en la migración siguiente (repetible). No lee filas durante `migrate --pretend`.
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::VIGENTE, Estado::ANULADO];

    public function up(): void
    {
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create('salidas_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas');
            $table->foreignId('categoria_gasto_id')->constrained('categorias_gasto');
            $table->string('beneficiario', 150);
            $table->string('comprobante', 60)->nullable();
            $table->text('concepto');
            // Guaraníes enteros, mayor que cero; sale siempre en efectivo.
            $table->bigInteger('monto');
            $table->foreignId('medio_pago_id')->constrained('medios_pago');
            // Previsto para la fase siguiente (el contado y el anticipo de una compra): por ahora siempre vacío.
            $table->foreignId('compra_id')->nullable()->constrained('compras');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamp('registrada_en');
            // VIGENTE o ANULADO.
            $table->foreignId('estado_id')->constrained('estados');
            $table->timestamp('anulada_en')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users');
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index('estado_id');
        });

        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->foreignId('salida_caja_id')->nullable()->constrained('salidas_caja');
            $table->unique(['salida_caja_id', 'tipo'], 'movimientos_caja_salida_por_tipo');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Solo quita la obligación: las filas no se tocan.
            DB::statement('ALTER TABLE solicitudes_reversion_cobro ALTER COLUMN cobro_id DROP NOT NULL');
        } else {
            // SQLite (los tests): la columna se cambia reconstruyendo la tabla.
            Schema::table('solicitudes_reversion_cobro', function (Blueprint $table) {
                $table->foreignId('cobro_id')->nullable()->change();
            });
        }
        Schema::table('solicitudes_reversion_cobro', function (Blueprint $table) {
            $table->foreignId('salida_caja_id')->nullable()->constrained('salidas_caja');
        });
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite reconstruye la tabla al cambiar la columna y al sumar la clave foránea, y pierde la condición del índice parcial:
            // se vuelve a crear como era.
            DB::statement('DROP INDEX IF EXISTS solicitudes_reversion_una_pendiente');
            DB::statement('CREATE UNIQUE INDEX solicitudes_reversion_una_pendiente ON solicitudes_reversion_cobro (cobro_id) WHERE revisado_en IS NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS solicitudes_reversion_cobro_cobro_id_index ON solicitudes_reversion_cobro (cobro_id)');
        }
        // Una sola solicitud pendiente por salida. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX solicitudes_anulacion_salida_una_pendiente ON solicitudes_reversion_cobro (salida_caja_id) WHERE revisado_en IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE salidas_caja ADD CONSTRAINT salidas_caja_valida CHECK (
                monto > 0 AND length(trim(beneficiario)) > 0 AND length(trim(concepto)) >= 5)");
            DB::statement('ALTER TABLE salidas_caja ADD CONSTRAINT salidas_caja_anulacion_completa CHECK (
                (anulada_en IS NULL AND anulada_por IS NULL AND motivo_anulacion IS NULL)
                OR (anulada_en IS NOT NULL AND anulada_por IS NOT NULL AND motivo_anulacion IS NOT NULL))');
            // La salida, del medio efectivo (el que sale del cajón).
            DB::statement('ALTER TABLE salidas_caja ADD COLUMN es_efectivo boolean NOT NULL DEFAULT true CHECK (es_efectivo)');
            DB::statement('ALTER TABLE salidas_caja ADD CONSTRAINT salidas_caja_en_efectivo
                FOREIGN KEY (medio_pago_id, es_efectivo) REFERENCES medios_pago (id, es_efectivo)');

            DB::statement('ALTER TABLE movimientos_caja DROP CONSTRAINT IF EXISTS movimientos_caja_tipo_valido');
            DB::statement("ALTER TABLE movimientos_caja ADD CONSTRAINT movimientos_caja_tipo_valido CHECK (
                (tipo = 'APERTURA' AND monto > 0 AND cobro_id IS NULL AND linea_cobro_id IS NULL AND salida_caja_id IS NULL)
                OR (tipo = 'COBRO' AND monto > 0 AND cobro_id IS NOT NULL AND linea_cobro_id IS NOT NULL AND salida_caja_id IS NULL)
                OR (tipo = 'REVERSION' AND monto < 0 AND cobro_id IS NOT NULL AND linea_cobro_id IS NOT NULL AND salida_caja_id IS NULL)
                OR (tipo = 'SALIDA' AND monto < 0 AND cobro_id IS NULL AND linea_cobro_id IS NULL AND salida_caja_id IS NOT NULL)
                OR (tipo = 'ANULACION_SALIDA' AND monto > 0 AND cobro_id IS NULL AND linea_cobro_id IS NULL AND salida_caja_id IS NOT NULL))");

            DB::statement('ALTER TABLE solicitudes_reversion_cobro ADD CONSTRAINT solicitudes_reversion_objeto CHECK (
                (cobro_id IS NOT NULL AND salida_caja_id IS NULL) OR (cobro_id IS NULL AND salida_caja_id IS NOT NULL))');
        }

        // Solo en una instalación existente (con módulos): en una nueva lo crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'SALIDAS_CAJA'], ['nombre' => 'Salidas de caja'])
                ->configurarEstados([Estado::VIGENTE, Estado::ANULADO]);
        }
    }

    /** Solo sin salidas cargadas (sus movimientos dejarían de cuadrar). El módulo y los estados quedan. */
    public function down(): void
    {
        if (DB::table('salidas_caja')->exists()) {
            throw new RuntimeException('Hay salidas de caja cargadas: no se deshace la migración.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE solicitudes_reversion_cobro DROP CONSTRAINT IF EXISTS solicitudes_reversion_objeto');
            DB::statement('ALTER TABLE movimientos_caja DROP CONSTRAINT IF EXISTS movimientos_caja_tipo_valido');
            DB::statement("ALTER TABLE movimientos_caja ADD CONSTRAINT movimientos_caja_tipo_valido CHECK (
                (tipo = 'APERTURA' AND monto > 0 AND cobro_id IS NULL AND linea_cobro_id IS NULL)
                OR (tipo = 'COBRO' AND monto > 0 AND cobro_id IS NOT NULL AND linea_cobro_id IS NOT NULL)
                OR (tipo = 'REVERSION' AND monto < 0 AND cobro_id IS NOT NULL AND linea_cobro_id IS NOT NULL))");
        }
        DB::statement('DROP INDEX IF EXISTS solicitudes_anulacion_salida_una_pendiente');
        Schema::table('solicitudes_reversion_cobro', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salida_caja_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE solicitudes_reversion_cobro ALTER COLUMN cobro_id SET NOT NULL');
        }
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->dropUnique('movimientos_caja_salida_por_tipo');
            $table->dropConstrainedForeignId('salida_caja_id');
        });
        Schema::dropIfExists('salidas_caja');
    }
};
