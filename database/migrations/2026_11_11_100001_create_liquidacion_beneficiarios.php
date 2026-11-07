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
 * Liquidación a beneficiarios del reparto (docs/liquidacion-beneficiarios.md): lo que le corresponde a cada profesional y a cada dueño
 * de equipo, por fecha de corte, su aprobación, sus pagos (totales o parciales) y los ajustes automáticos. Aditiva:
 *
 * - `liquidaciones_beneficiario`: el beneficiario (PROFESIONAL con su profesional, o EQUIPO con el proveedor dueño), la fecha de corte,
 *   el estado, el total (la suma exacta de sus líneas no quitadas; puede ser negativo por los ajustes), quién la armó, la envió, la
 *   aprobó o la devolvió y la anulación con motivo. Una sola abierta por beneficiario (`liquidaciones_beneficiario_una_abierta`).
 * - `lineas_liquidacion_beneficiario`: ORDINARIA (una línea del reparto, con el snapshot), AJUSTE (en contra de una ordinaria ya
 *   aprobada que dejó de corresponder) o SALDO_ANTERIOR (el saldo negativo de una liquidación aprobada anterior). Se liberan (QUITADA
 *   o ANULADA), nunca se borran.
 * - `pagos_beneficiario`: monto, fecha, medio, comprobante y, si salió en efectivo de una caja, la caja, la salida y su categoría de
 *   gasto. `cuenta_por_pagar_id` queda vacío: previsto para el objetivo 12 (cuentas por pagar), sin clave foránea porque esa tabla
 *   todavía no existe.
 *
 * Los cuatro módulos y sus permisos (`PerfilesPredefinidos::PERMISOS_LIQUIDACION_BENEFICIARIOS`, solo suma). No lee filas durante
 * `migrate --pretend`, no toca prestaciones, repartos, cobros, cajas, facturas ni liquidaciones a aseguradoras, no liquida nada y, en
 * una instalación nueva, deja los módulos al seeder. El `down` solo vale sin liquidaciones a beneficiarios.
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::EN_PREPARACION, Estado::PENDIENTE_APROBACION, Estado::APROBADO, Estado::PARCIAL, Estado::PAGADO,
        Estado::ANULADO, Estado::VIGENTE];

    public function up(): void
    {
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create('liquidaciones_beneficiario', function (Blueprint $table) {
            $table->id();
            // PROFESIONAL (con profesional_id) o EQUIPO (con proveedor_id: el dueño; todas sus máquinas en una sola).
            $table->string('beneficiario', 20);
            $table->foreignId('profesional_id')->nullable()->constrained('profesionales');
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
            $table->date('fecha_corte');
            $table->foreignId('estado_id')->constrained('estados');
            // La suma exacta de sus líneas no quitadas, en guaraníes enteros. Negativa si los ajustes superan lo nuevo.
            $table->decimal('total', 15, 2);
            $table->foreignId('moneda_id')->constrained('monedas');
            $table->timestamp('armada_en');
            $table->foreignId('armada_por')->constrained('users');
            $table->timestamp('enviada_en')->nullable();
            $table->foreignId('enviada_por')->nullable()->constrained('users');
            $table->timestamp('aprobada_en')->nullable();
            $table->foreignId('aprobada_por')->nullable()->constrained('users');
            $table->timestamp('devuelta_en')->nullable();
            $table->foreignId('devuelta_por')->nullable()->constrained('users');
            $table->text('nota_devolucion')->nullable();
            $table->timestamp('anulada_en')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('users');
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index(['beneficiario', 'profesional_id']);
            $table->index(['beneficiario', 'proveedor_id']);
        });
        // Una sola abierta (en preparación o pendiente de aprobación) por beneficiario. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX liquidaciones_beneficiario_una_abierta ON liquidaciones_beneficiario
            (beneficiario, COALESCE(profesional_id, 0), COALESCE(proveedor_id, 0)) WHERE aprobada_en IS NULL AND anulada_en IS NULL');

        Schema::create('lineas_liquidacion_beneficiario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_beneficiario_id')->constrained('liquidaciones_beneficiario');
            // ORDINARIA, AJUSTE o SALDO_ANTERIOR.
            $table->string('tipo', 20);
            // ORDINARIA y AJUSTE: la línea del reparto y su prestación (trazabilidad hasta la prestación original).
            $table->foreignId('linea_reparto_id')->nullable()->constrained('lineas_reparto');
            $table->foreignId('prestacion_id')->nullable()->constrained('prestaciones');
            // ORDINARIA: cuántas veces entró esa línea del reparto (1 la primera; otra vez solo después de un ajuste aprobado).
            $table->unsignedInteger('vez')->nullable();
            // AJUSTE: la ordinaria (ya aprobada) que compensa.
            $table->foreignId('ajusta_linea_id')->nullable()->constrained('lineas_liquidacion_beneficiario');
            // SALDO_ANTERIOR: la liquidación aprobada con total negativo cuyo saldo se traslada.
            $table->foreignId('saldo_de_liquidacion_id')->nullable()->constrained('liquidaciones_beneficiario');
            // El snapshot de la planilla. Sin nada clínico; la paciente, solo en las del profesional.
            $table->date('fecha_servicio')->nullable();
            $table->string('paciente', 200)->nullable();
            $table->string('procedimiento', 150)->nullable();
            $table->string('equipo', 150)->nullable();
            $table->decimal('monto_base', 15, 2)->nullable();
            $table->decimal('porcentaje', 5, 2)->nullable();
            // Positivo en la ordinaria; negativo en el ajuste y en el saldo anterior.
            $table->decimal('monto', 15, 2);
            // AJUSTE: por qué dejó de corresponder (cobro revertido, factura anulada, visación aplicada, prestación anulada...).
            $table->string('motivo_ajuste', 300)->nullable();
            $table->string('liberacion', 12)->nullable();
            $table->timestamp('liberada_en')->nullable();
            $table->foreignId('liberada_por')->nullable()->constrained('users');
            $table->text('motivo_liberacion')->nullable();
            $table->timestamps();

            $table->index('liquidacion_beneficiario_id');
            $table->index('linea_reparto_id');
            $table->index('prestacion_id');
        });
        // Una línea del reparto entra una sola vez por "vez"; un ajuste por ordinaria; un saldo trasladado una sola vez.
        DB::statement("CREATE UNIQUE INDEX lineas_liq_beneficiario_una_ordinaria ON lineas_liquidacion_beneficiario (linea_reparto_id, vez)
            WHERE tipo = 'ORDINARIA' AND liberada_en IS NULL");
        DB::statement("CREATE UNIQUE INDEX lineas_liq_beneficiario_un_ajuste ON lineas_liquidacion_beneficiario (ajusta_linea_id)
            WHERE tipo = 'AJUSTE' AND liberada_en IS NULL");
        DB::statement("CREATE UNIQUE INDEX lineas_liq_beneficiario_un_saldo ON lineas_liquidacion_beneficiario (saldo_de_liquidacion_id)
            WHERE tipo = 'SALDO_ANTERIOR' AND liberada_en IS NULL");

        Schema::create('pagos_beneficiario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_beneficiario_id')->constrained('liquidaciones_beneficiario');
            $table->decimal('monto', 15, 2);
            $table->date('fecha');
            $table->foreignId('medio_pago_id')->constrained('medios_pago');
            // El comprobante (obligatorio según el medio: MedioPago::exigeComprobante).
            $table->string('referencia', 100)->nullable();
            // En efectivo desde una caja abierta de hoy: la caja, la salida que lo registra y su categoría de gasto.
            $table->foreignId('caja_id')->nullable()->constrained('cajas');
            $table->foreignId('salida_caja_id')->nullable()->unique()->constrained('salidas_caja');
            $table->foreignId('categoria_gasto_id')->nullable()->constrained('categorias_gasto');
            // Previsto para el objetivo 12 (cuentas por pagar): vacío; sin clave foránea (la tabla no existe todavía).
            $table->unsignedBigInteger('cuenta_por_pagar_id')->nullable();
            $table->foreignId('estado_id')->constrained('estados');
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamp('anulado_en')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users');
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index('liquidacion_beneficiario_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE liquidaciones_beneficiario ADD CONSTRAINT liquidaciones_beneficiario_clave_valida CHECK (
                (beneficiario = 'PROFESIONAL' AND profesional_id IS NOT NULL AND proveedor_id IS NULL)
                OR (beneficiario = 'EQUIPO' AND proveedor_id IS NOT NULL AND profesional_id IS NULL))");
            DB::statement('ALTER TABLE liquidaciones_beneficiario ADD CONSTRAINT liquidaciones_beneficiario_total_entero CHECK (total = trunc(total))');
            DB::statement('ALTER TABLE liquidaciones_beneficiario ADD CONSTRAINT liquidaciones_beneficiario_envio_completo CHECK ((enviada_en IS NULL) = (enviada_por IS NULL))');
            DB::statement('ALTER TABLE liquidaciones_beneficiario ADD CONSTRAINT liquidaciones_beneficiario_aprobacion_completa CHECK (
                (aprobada_en IS NULL) = (aprobada_por IS NULL) AND (aprobada_por IS NULL OR aprobada_por <> armada_por))');
            DB::statement('ALTER TABLE liquidaciones_beneficiario ADD CONSTRAINT liquidaciones_beneficiario_devolucion_completa CHECK (
                (devuelta_en IS NULL AND devuelta_por IS NULL AND nota_devolucion IS NULL)
                OR (devuelta_en IS NOT NULL AND devuelta_por IS NOT NULL AND nota_devolucion IS NOT NULL))');
            DB::statement('ALTER TABLE liquidaciones_beneficiario ADD CONSTRAINT liquidaciones_beneficiario_anulacion_completa CHECK (
                (anulada_en IS NULL AND anulada_por IS NULL AND motivo_anulacion IS NULL)
                OR (anulada_en IS NOT NULL AND anulada_por IS NOT NULL AND motivo_anulacion IS NOT NULL))');
            DB::statement("ALTER TABLE lineas_liquidacion_beneficiario ADD CONSTRAINT lineas_liq_beneficiario_tipo_valido CHECK (
                (tipo = 'ORDINARIA' AND linea_reparto_id IS NOT NULL AND prestacion_id IS NOT NULL AND vez >= 1 AND ajusta_linea_id IS NULL
                    AND saldo_de_liquidacion_id IS NULL AND monto > 0)
                OR (tipo = 'AJUSTE' AND linea_reparto_id IS NOT NULL AND prestacion_id IS NOT NULL AND vez IS NULL AND ajusta_linea_id IS NOT NULL
                    AND saldo_de_liquidacion_id IS NULL AND monto < 0 AND motivo_ajuste IS NOT NULL)
                OR (tipo = 'SALDO_ANTERIOR' AND linea_reparto_id IS NULL AND prestacion_id IS NULL AND vez IS NULL AND ajusta_linea_id IS NULL
                    AND saldo_de_liquidacion_id IS NOT NULL AND monto < 0))");
            DB::statement('ALTER TABLE lineas_liquidacion_beneficiario ADD CONSTRAINT lineas_liq_beneficiario_monto_entero CHECK (monto = trunc(monto))');
            DB::statement("ALTER TABLE lineas_liquidacion_beneficiario ADD CONSTRAINT lineas_liq_beneficiario_liberacion_completa CHECK (
                (liberacion IS NULL AND liberada_en IS NULL AND liberada_por IS NULL AND motivo_liberacion IS NULL)
                OR (liberacion IN ('QUITADA', 'ANULADA') AND liberada_en IS NOT NULL AND liberada_por IS NOT NULL AND motivo_liberacion IS NOT NULL))");
            DB::statement('ALTER TABLE pagos_beneficiario ADD CONSTRAINT pagos_beneficiario_monto_positivo CHECK (monto > 0 AND monto = trunc(monto))');
            DB::statement('ALTER TABLE pagos_beneficiario ADD CONSTRAINT pagos_beneficiario_caja_completa CHECK (
                (caja_id IS NULL AND salida_caja_id IS NULL AND categoria_gasto_id IS NULL)
                OR (caja_id IS NOT NULL AND salida_caja_id IS NOT NULL AND categoria_gasto_id IS NOT NULL))');
            DB::statement('ALTER TABLE pagos_beneficiario ADD CONSTRAINT pagos_beneficiario_anulacion_completa CHECK (
                (anulado_en IS NULL AND anulado_por IS NULL AND motivo_anulacion IS NULL)
                OR (anulado_en IS NOT NULL AND anulado_por IS NOT NULL AND motivo_anulacion IS NOT NULL))');
        }

        // Solo en una instalación existente (con módulos): en una nueva los crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            foreach (PerfilesPredefinidos::MODULOS_LIQUIDACION_BENEFICIARIOS as $codigo => [$nombre, $estados]) {
                ModuloSistema::updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre])->configurarEstados($estados);
            }
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            foreach (PerfilesPredefinidos::PERMISOS_LIQUIDACION_BENEFICIARIOS as $modulo => $accionesPorPerfil) {
                if (ModuloSistema::where('codigo', $modulo)->exists()) {
                    PerfilesPredefinidos::sumarModulo($modulo, $accionesPorPerfil);
                }
            }
        }
    }

    /** Solo sin liquidaciones a beneficiarios (con alguna, se rechaza: nada se borra). Los módulos, los permisos y los estados quedan. */
    public function down(): void
    {
        if (Schema::hasTable('liquidaciones_beneficiario') && DB::table('liquidaciones_beneficiario')->exists()) {
            throw new RuntimeException('Hay liquidaciones a beneficiarios cargadas: esta migración no se revierte.');
        }
        Schema::dropIfExists('pagos_beneficiario');
        Schema::dropIfExists('lineas_liquidacion_beneficiario');
        Schema::dropIfExists('liquidaciones_beneficiario');
    }
};
