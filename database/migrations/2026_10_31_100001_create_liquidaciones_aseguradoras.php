<?php

use App\Models\Estado;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4b económica (docs/liquidaciones-aseguradoras.md): la liquidación mensual a cada aseguradora (la planilla), su aprobación y
 * los pagos parciales de la aseguradora. Aditiva:
 *
 * - `liquidaciones`: aseguradora (responsable de pago), convenio, fecha de corte, estado, total, quién la armó, la envió, la aprobó o
 *   la devolvió, la marca "pendiente de facturar" y la anulación con motivo. Una sola abierta (en preparación o pendiente de
 *   aprobación) por aseguradora (`liquidaciones_una_abierta`).
 * - `lineas_liquidacion`: una por prestación, con el snapshot de la planilla. Una prestación se liquida una sola vez
 *   (`lineas_liquidacion_una_por_prestacion`, entre las líneas no liberadas: las quitadas y las de liquidaciones anuladas quedan
 *   libres para la siguiente).
 * - `pagos_aseguradora`: los pagos (parciales) de una liquidación aprobada, vigentes o anulados con motivo. No pasan por Caja.
 *
 * El estado que falta (PENDIENTE_APROBACION; PARCIAL y PAGADO ya existían), los tres módulos y sus permisos
 * (`PerfilesPredefinidos::PERMISOS_LIQUIDACIONES`). No lee filas durante `migrate --pretend`, no liquida ninguna prestación ni toca
 * las prestaciones, los cobros ni las visaciones existentes y, en una instalación nueva, deja los módulos al seeder.
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::EN_PREPARACION, Estado::PENDIENTE_APROBACION, Estado::APROBADO, Estado::PARCIAL, Estado::PAGADO,
        Estado::ANULADO, Estado::VIGENTE];

    public function up(): void
    {
        // Con DB::table, y no con Estado::idDe, para que `migrate --pretend`, que no devuelve filas, no falle.
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create('liquidaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('responsable_pago_id')->constrained('responsables_pago');
            // El convenio de sus prestaciones (vacío si son de más de un convenio de la misma aseguradora).
            $table->foreignId('convenio_id')->nullable()->constrained('convenios');
            $table->date('fecha_corte');
            // EN_PREPARACION, PENDIENTE_APROBACION, APROBADO, PARCIAL (pagada en parte), PAGADO o ANULADO.
            $table->foreignId('estado_id')->constrained('estados');
            // La suma exacta de sus líneas no quitadas, en guaraníes enteros.
            $table->decimal('total', 15, 2);
            $table->foreignId('moneda_id')->constrained('monedas');
            $table->timestamp('armada_en');
            $table->foreignId('armada_por')->constrained('users');
            $table->timestamp('enviada_en')->nullable();
            $table->foreignId('enviada_por')->nullable()->constrained('users');
            $table->timestamp('aprobada_en')->nullable();
            $table->foreignId('aprobada_por')->nullable()->constrained('users');
            // La última devolución (las anteriores quedan en Auditoría).
            $table->timestamp('devuelta_en')->nullable();
            $table->foreignId('devuelta_por')->nullable()->constrained('users');
            $table->text('nota_devolucion')->nullable();
            // PENDIENTE = pendiente de facturar (se marca al aprobarla; la factura es de la fase siguiente).
            $table->string('facturacion', 12)->nullable();
            $table->timestamp('anulada_en')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users');
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index('responsable_pago_id');
        });
        // Una sola abierta (en preparación o pendiente de aprobación) por aseguradora. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX liquidaciones_una_abierta ON liquidaciones (responsable_pago_id) WHERE aprobada_en IS NULL AND anulada_en IS NULL');

        Schema::create('lineas_liquidacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_id')->constrained('liquidaciones');
            $table->foreignId('prestacion_id')->constrained('prestaciones');
            // El snapshot de la planilla: no cambia aunque cambien la paciente o la prestación. Sin nada clínico.
            $table->string('paciente', 200);
            $table->string('documento', 40);
            $table->string('numero_asegurado', 50);
            $table->date('fecha_servicio');
            $table->string('procedimiento', 150);
            $table->string('numero_visacion', 50);
            // Lo que paga la aseguradora por esa prestación.
            $table->decimal('monto', 15, 2);
            // Liberada: QUITADA de la liquidación en preparación (con motivo) o ANULADA con la liquidación. Vuelve a quedar libre.
            $table->string('liberacion', 12)->nullable();
            $table->timestamp('liberada_en')->nullable();
            $table->foreignId('liberada_por')->nullable()->constrained('users');
            $table->text('motivo_liberacion')->nullable();
            $table->timestamps();

            $table->index('liquidacion_id');
            $table->index('prestacion_id');
        });
        // Una prestación se liquida una sola vez. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX lineas_liquidacion_una_por_prestacion ON lineas_liquidacion (prestacion_id) WHERE liberada_en IS NULL');

        Schema::create('pagos_aseguradora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_id')->constrained('liquidaciones');
            $table->decimal('monto', 15, 2);
            $table->date('fecha');
            $table->foreignId('medio_pago_id')->constrained('medios_pago');
            // Número de transferencia o de cheque, opcional.
            $table->string('referencia', 100)->nullable();
            // VIGENTE o ANULADO (con motivo, quién y cuándo). Nunca se borra.
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamp('anulado_en')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users');
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index('liquidacion_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE liquidaciones ADD CONSTRAINT liquidaciones_total_valido CHECK (total >= 0 AND total = trunc(total))');
            DB::statement('ALTER TABLE liquidaciones ADD CONSTRAINT liquidaciones_envio_completo CHECK ((enviada_en IS NULL) = (enviada_por IS NULL))');
            DB::statement('ALTER TABLE liquidaciones ADD CONSTRAINT liquidaciones_aprobacion_completa CHECK ((aprobada_en IS NULL) = (aprobada_por IS NULL)
                AND (aprobada_por IS NULL OR aprobada_por <> armada_por))');
            DB::statement('ALTER TABLE liquidaciones ADD CONSTRAINT liquidaciones_devolucion_completa CHECK (
                (devuelta_en IS NULL AND devuelta_por IS NULL AND nota_devolucion IS NULL)
                OR (devuelta_en IS NOT NULL AND devuelta_por IS NOT NULL AND nota_devolucion IS NOT NULL))');
            DB::statement("ALTER TABLE liquidaciones ADD CONSTRAINT liquidaciones_facturacion_valida CHECK (
                facturacion IS NULL OR (facturacion = 'PENDIENTE' AND aprobada_en IS NOT NULL))");
            DB::statement('ALTER TABLE liquidaciones ADD CONSTRAINT liquidaciones_anulacion_completa CHECK (
                (anulada_en IS NULL AND anulada_por IS NULL AND motivo_anulacion IS NULL)
                OR (anulada_en IS NOT NULL AND anulada_por IS NOT NULL AND motivo_anulacion IS NOT NULL))');
            DB::statement('ALTER TABLE lineas_liquidacion ADD CONSTRAINT lineas_liquidacion_monto_positivo CHECK (monto > 0 AND monto = trunc(monto))');
            DB::statement("ALTER TABLE lineas_liquidacion ADD CONSTRAINT lineas_liquidacion_liberacion_completa CHECK (
                (liberacion IS NULL AND liberada_en IS NULL AND liberada_por IS NULL AND motivo_liberacion IS NULL)
                OR (liberacion IN ('QUITADA', 'ANULADA') AND liberada_en IS NOT NULL AND liberada_por IS NOT NULL AND motivo_liberacion IS NOT NULL))");
            DB::statement('ALTER TABLE pagos_aseguradora ADD CONSTRAINT pagos_aseguradora_monto_positivo CHECK (monto > 0 AND monto = trunc(monto))');
            DB::statement('ALTER TABLE pagos_aseguradora ADD CONSTRAINT pagos_aseguradora_anulacion_completa CHECK (
                (anulado_en IS NULL AND anulado_por IS NULL AND motivo_anulacion IS NULL)
                OR (anulado_en IS NOT NULL AND anulado_por IS NOT NULL AND motivo_anulacion IS NOT NULL))');
        }

        // Solo en una instalación existente (con módulos): en una nueva los crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'LIQUIDACIONES'], ['nombre' => 'Liquidaciones a aseguradoras'])
                ->configurarEstados([Estado::EN_PREPARACION, Estado::PENDIENTE_APROBACION, Estado::APROBADO, Estado::PARCIAL, Estado::PAGADO, Estado::ANULADO]);
            ModuloSistema::updateOrCreate(['codigo' => 'APROBACION_LIQUIDACIONES'], ['nombre' => 'Aprobación de liquidaciones'])
                ->configurarEstados([Estado::PENDIENTE_APROBACION, Estado::APROBADO, Estado::EN_PREPARACION]);
            ModuloSistema::updateOrCreate(['codigo' => 'PAGOS_ASEGURADORAS'], ['nombre' => 'Pagos de aseguradoras'])
                ->configurarEstados([Estado::VIGENTE, Estado::ANULADO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            foreach (PerfilesPredefinidos::PERMISOS_LIQUIDACIONES as $modulo => $accionesPorPerfil) {
                if (ModuloSistema::where('codigo', $modulo)->exists()) {
                    PerfilesPredefinidos::sumarModulo($modulo, $accionesPorPerfil);
                }
            }
        }
    }

    /** Solo sin liquidaciones. Los módulos, los permisos y los estados quedan. */
    public function down(): void
    {
        Schema::dropIfExists('pagos_aseguradora');
        Schema::dropIfExists('lineas_liquidacion');
        Schema::dropIfExists('liquidaciones');
    }
};
