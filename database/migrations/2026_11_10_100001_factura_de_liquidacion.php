<?php

use App\Models\Estado;
use App\Models\ModuloSistema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La factura de la liquidación a la aseguradora (docs/facturacion.md, fase 5b): una factura CRÉDITO por liquidación aprobada, a nombre
 * de la aseguradora, con una línea por prestación (parte ASEGURADORA). Aditiva: no cambia ninguna factura, cobro, caja ni liquidación
 * ya cargada.
 *
 * - `facturas.paciente_id` y `facturas.caja_id` dejan de ser obligatorios (la de crédito no tiene paciente ni caja). Solo se quita la
 *   obligación: las filas no se tocan.
 * - `facturas.liquidacion_id` (vacío en las existentes) y una sola factura no anulada por liquidación (`facturas_una_por_liquidacion`,
 *   índice único parcial, SQLite y PostgreSQL).
 * - En PostgreSQL, `facturas_condicion_valida` admite CONTADO y CREDITO, y `facturas_cliente_segun_condicion` exige paciente y caja en la
 *   contado y la liquidación en la de crédito (las existentes, todas CONTADO con paciente y caja, lo cumplen).
 *   `liquidaciones_facturacion_valida` admite PENDIENTE y FACTURADA.
 *
 * El módulo FACTURAS_LIQUIDACION, solo en una instalación existente (en una nueva lo crea ModuloSistemaSeeder). Los permisos, en la
 * migración siguiente (repetible). No lee filas durante `migrate --pretend`.
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::EMITIDO, Estado::COBRADO, Estado::ANULADO];

    public function up(): void
    {
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            // Solo quita la obligación: las filas no se tocan.
            DB::statement('ALTER TABLE facturas ALTER COLUMN paciente_id DROP NOT NULL');
            DB::statement('ALTER TABLE facturas ALTER COLUMN caja_id DROP NOT NULL');
        } else {
            // SQLite (los tests): la columna se cambia reconstruyendo la tabla.
            Schema::table('facturas', function (Blueprint $table) {
                $table->foreignId('paciente_id')->nullable()->change();
                $table->foreignId('caja_id')->nullable()->change();
            });
        }
        Schema::table('facturas', function (Blueprint $table) {
            // La liquidación que factura (solo la de crédito; vacío en la contado y en las existentes).
            $table->foreignId('liquidacion_id')->nullable()->constrained('liquidaciones');
            $table->index('liquidacion_id');
        });
        // Una sola factura no anulada por liquidación. Vale en SQLite y PostgreSQL (los NULL de la contado no chocan).
        DB::statement('CREATE UNIQUE INDEX facturas_una_por_liquidacion ON facturas (liquidacion_id) WHERE anulada_en IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE facturas DROP CONSTRAINT IF EXISTS facturas_condicion_valida');
            DB::statement("ALTER TABLE facturas ADD CONSTRAINT facturas_condicion_valida CHECK (condicion IN ('CONTADO', 'CREDITO'))");
            DB::statement("ALTER TABLE facturas ADD CONSTRAINT facturas_cliente_segun_condicion CHECK (
                (condicion = 'CONTADO' AND paciente_id IS NOT NULL AND caja_id IS NOT NULL AND liquidacion_id IS NULL)
                OR (condicion = 'CREDITO' AND liquidacion_id IS NOT NULL AND paciente_id IS NULL AND caja_id IS NULL AND reemplaza_a_id IS NULL))");
            DB::statement('ALTER TABLE liquidaciones DROP CONSTRAINT IF EXISTS liquidaciones_facturacion_valida');
            DB::statement("ALTER TABLE liquidaciones ADD CONSTRAINT liquidaciones_facturacion_valida CHECK (
                facturacion IS NULL OR (facturacion IN ('PENDIENTE', 'FACTURADA') AND aprobada_en IS NOT NULL))");
        }

        // Solo en una instalación existente (con módulos): en una nueva lo crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'FACTURAS_LIQUIDACION'], ['nombre' => 'Facturas de liquidaciones'])
                ->configurarEstados(self::ESTADOS);
        }
    }

    /** Solo sin facturas de liquidación. El módulo y los estados quedan. */
    public function down(): void
    {
        if (DB::table('facturas')->whereNotNull('liquidacion_id')->exists()) {
            throw new RuntimeException('Hay facturas de liquidaciones: no se deshace la migración.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE liquidaciones DROP CONSTRAINT IF EXISTS liquidaciones_facturacion_valida');
            DB::statement("ALTER TABLE liquidaciones ADD CONSTRAINT liquidaciones_facturacion_valida CHECK (
                facturacion IS NULL OR (facturacion = 'PENDIENTE' AND aprobada_en IS NOT NULL))");
            DB::statement('ALTER TABLE facturas DROP CONSTRAINT IF EXISTS facturas_cliente_segun_condicion');
            DB::statement('ALTER TABLE facturas DROP CONSTRAINT IF EXISTS facturas_condicion_valida');
            DB::statement("ALTER TABLE facturas ADD CONSTRAINT facturas_condicion_valida CHECK (condicion = 'CONTADO')");
        }
        DB::statement('DROP INDEX IF EXISTS facturas_una_por_liquidacion');
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropIndex(['liquidacion_id']);
        });
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('liquidacion_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE facturas ALTER COLUMN paciente_id SET NOT NULL');
            DB::statement('ALTER TABLE facturas ALTER COLUMN caja_id SET NOT NULL');
        }
    }
};
