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
 * Fase 3b económica (docs/cobros-caja.md): la reversión de un cobro completo. Aditiva:
 *
 * - `cobros`: `revertido_en`, `revertido_por` (quien devuelve el dinero), `motivo_reversion` y `caja_reversion_id` (la caja ABIERTA
 *   donde se registró el egreso). Vacíos = vigente: los cobros existentes no cambian.
 * - `solicitudes_reversion_cobro`: la reversión que necesita aprobación (caja cerrada, devuelta o aprobada, o de otro cajero). Una
 *   sola pendiente por cobro (`solicitudes_reversion_una_pendiente`, índice único parcial: pendiente = sin `revisado_en`).
 * - `movimientos_caja`: el tipo REVERSION (egreso, en negativo, uno por línea del cobro). El único de `linea_cobro_id` pasa a
 *   ser por línea y tipo (`movimientos_caja_linea_por_tipo`): una entrada y a lo sumo una devolución por línea.
 * - `medios_pago.es_efectivo`: el medio que sale del cajón físico (a lo sumo uno: `medios_pago_un_efectivo`); se marca el de código
 *   EFECTIVO. `cierre_caja_medios.es_efectivo`: la copia de ese dato en cada medio del cierre (en PostgreSQL, atada al medio por
 *   clave foránea), para que lo esperado y lo contado solo tengan que ser >= 0 en el efectivo: en los demás medios pueden quedar
 *   negativos por las devoluciones. Los cierres existentes solo reciben esa marca; sus montos no cambian.
 *
 * El estado REVERTIDO (el único nuevo; PENDIENTE, APROBADO, RECHAZADO y VIGENTE ya existían), el módulo REVERSIONES_COBRO (VER las
 * solicitudes, EDITAR = aprobar o rechazar) y la acción DESACTIVAR sobre COBROS (revertir y solicitar). No revierte nada, no lee
 * filas durante `migrate --pretend` y, en una instalación nueva, deja el módulo al seeder.
 */
return new class extends Migration
{
    private const ESTADOS = [Estado::VIGENTE, Estado::REVERTIDO, Estado::PENDIENTE, Estado::APROBADO, Estado::RECHAZADO];

    public function up(): void
    {
        // Con DB::table, y no con Estado::idDe, para que `migrate --pretend`, que no devuelve filas, no falle.
        foreach (self::ESTADOS as $codigo) {
            if (! DB::table('estados')->where('codigo', $codigo)->exists()) {
                DB::table('estados')->insert(['codigo' => $codigo, 'nombre' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::table('cobros', function (Blueprint $table) {
            $table->timestamp('revertido_en')->nullable();
            // Quien devuelve el dinero: el cajero que revierte o el que pidió la solicitud aprobada.
            $table->foreignId('revertido_por')->nullable()->constrained('users');
            $table->text('motivo_reversion')->nullable();
            // La caja abierta de quien devuelve, donde quedó el egreso. Puede ser otra que la del cobro.
            $table->foreignId('caja_reversion_id')->nullable()->constrained('cajas');
        });

        Schema::create('solicitudes_reversion_cobro', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cobro_id')->constrained('cobros');
            // El solicitante: devuelve el dinero desde su caja abierta cuando se aprueba.
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestamp('solicitada_en');
            $table->text('motivo');
            // PENDIENTE, APROBADO o RECHAZADO.
            $table->foreignId('estado_id')->constrained('estados');
            // Vacío = pendiente.
            $table->foreignId('revisado_por')->nullable()->constrained('users');
            $table->timestamp('revisado_en')->nullable();
            $table->text('nota_revision')->nullable();
            $table->timestamps();

            $table->index('estado_id');
        });
        // Una sola solicitud pendiente por cobro. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX solicitudes_reversion_una_pendiente ON solicitudes_reversion_cobro (cobro_id) WHERE revisado_en IS NULL');
        DB::statement('CREATE INDEX solicitudes_reversion_cobro_cobro_id_index ON solicitudes_reversion_cobro (cobro_id)');

        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->dropUnique(['linea_cobro_id']);
            $table->unique(['linea_cobro_id', 'tipo'], 'movimientos_caja_linea_por_tipo');
        });

        Schema::table('medios_pago', function (Blueprint $table) {
            $table->boolean('es_efectivo')->default(false);
        });
        DB::table('medios_pago')->where('codigo', 'EFECTIVO')->update(['es_efectivo' => true]);
        // A lo sumo un medio efectivo. Vale en SQLite y PostgreSQL.
        DB::statement('CREATE UNIQUE INDEX medios_pago_un_efectivo ON medios_pago (es_efectivo) WHERE es_efectivo');

        Schema::table('cierre_caja_medios', function (Blueprint $table) {
            $table->boolean('es_efectivo')->default(false);
        });
        DB::table('cierre_caja_medios')->whereIn('medio_pago_id', fn ($query) => $query->select('id')->from('medios_pago')->where('es_efectivo', true))
            ->update(['es_efectivo' => true]);

        if (DB::getDriverName() === 'pgsql') {
            // Lo esperado y lo contado, >= 0 solo en el efectivo; la marca, la de su medio de pago.
            DB::statement('ALTER TABLE medios_pago ADD CONSTRAINT medios_pago_id_es_efectivo_unique UNIQUE (id, es_efectivo)');
            DB::statement('ALTER TABLE cierre_caja_medios ADD CONSTRAINT cierre_caja_medios_es_efectivo_del_medio
                FOREIGN KEY (medio_pago_id, es_efectivo) REFERENCES medios_pago (id, es_efectivo)');
            DB::statement('ALTER TABLE cierre_caja_medios DROP CONSTRAINT cierre_caja_medios_valores_validos');
            DB::statement('ALTER TABLE cierre_caja_medios ADD CONSTRAINT cierre_caja_medios_valores_validos CHECK (
                diferencia = contado - esperado AND (NOT es_efectivo OR (esperado >= 0 AND contado >= 0)))');

            DB::statement('ALTER TABLE movimientos_caja DROP CONSTRAINT movimientos_caja_tipo_valido');
            DB::statement("ALTER TABLE movimientos_caja ADD CONSTRAINT movimientos_caja_tipo_valido CHECK (
                (tipo = 'APERTURA' AND monto > 0 AND cobro_id IS NULL AND linea_cobro_id IS NULL)
                OR (tipo = 'COBRO' AND monto > 0 AND cobro_id IS NOT NULL AND linea_cobro_id IS NOT NULL)
                OR (tipo = 'REVERSION' AND monto < 0 AND cobro_id IS NOT NULL AND linea_cobro_id IS NOT NULL))");
            DB::statement('ALTER TABLE cobros ADD CONSTRAINT cobros_reversion_completa CHECK (
                (revertido_en IS NULL AND revertido_por IS NULL AND motivo_reversion IS NULL AND caja_reversion_id IS NULL)
                OR (revertido_en IS NOT NULL AND revertido_por IS NOT NULL AND motivo_reversion IS NOT NULL AND caja_reversion_id IS NOT NULL))');
            DB::statement('ALTER TABLE solicitudes_reversion_cobro ADD CONSTRAINT solicitudes_reversion_revision_completa CHECK (
                (revisado_en IS NULL AND revisado_por IS NULL) OR (revisado_en IS NOT NULL AND revisado_por IS NOT NULL))');
        }

        // Solo en una instalación existente (con módulos): en una nueva los crea ModuloSistemaSeeder.
        if (DB::table('modulos_sistema')->exists()) {
            ModuloSistema::updateOrCreate(['codigo' => 'COBROS'], ['nombre' => 'Cobros'])
                ->configurarEstados([Estado::VIGENTE, Estado::REVERTIDO]);
            ModuloSistema::updateOrCreate(['codigo' => 'REVERSIONES_COBRO'], ['nombre' => 'Aprobación de reversiones de cobro'])
                ->configurarEstados([Estado::PENDIENTE, Estado::APROBADO, Estado::RECHAZADO]);
        }

        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            foreach (PerfilesPredefinidos::PERMISOS_REVERSIONES_COBRO as $modulo => $accionesPorPerfil) {
                if (ModuloSistema::where('codigo', $modulo)->exists()) {
                    PerfilesPredefinidos::sumarModulo($modulo, $accionesPorPerfil);
                }
            }
        }
    }

    /**
     * Solo sin cobros revertidos ni cierres con negativos (sus devoluciones no tendrían dónde quedar). El módulo, los permisos y los
     * estados quedan.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cierre_caja_medios DROP CONSTRAINT IF EXISTS cierre_caja_medios_valores_validos');
            DB::statement('ALTER TABLE cierre_caja_medios ADD CONSTRAINT cierre_caja_medios_valores_validos CHECK (esperado >= 0 AND contado >= 0 AND diferencia = contado - esperado)');
            DB::statement('ALTER TABLE cierre_caja_medios DROP CONSTRAINT IF EXISTS cierre_caja_medios_es_efectivo_del_medio');
            DB::statement('ALTER TABLE medios_pago DROP CONSTRAINT IF EXISTS medios_pago_id_es_efectivo_unique');
            DB::statement('ALTER TABLE cobros DROP CONSTRAINT IF EXISTS cobros_reversion_completa');
            DB::statement('ALTER TABLE movimientos_caja DROP CONSTRAINT IF EXISTS movimientos_caja_tipo_valido');
            DB::statement("ALTER TABLE movimientos_caja ADD CONSTRAINT movimientos_caja_tipo_valido CHECK (
                (tipo = 'APERTURA' AND monto > 0 AND cobro_id IS NULL AND linea_cobro_id IS NULL)
                OR (tipo = 'COBRO' AND monto > 0 AND cobro_id IS NOT NULL AND linea_cobro_id IS NOT NULL))");
        }
        Schema::table('movimientos_caja', function (Blueprint $table) {
            $table->dropUnique('movimientos_caja_linea_por_tipo');
            $table->unique('linea_cobro_id');
        });
        Schema::dropIfExists('solicitudes_reversion_cobro');
        Schema::table('cobros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_reversion_id');
            $table->dropConstrainedForeignId('revertido_por');
        });
        Schema::table('cobros', function (Blueprint $table) {
            $table->dropColumn(['revertido_en', 'motivo_reversion']);
        });
        Schema::table('cierre_caja_medios', function (Blueprint $table) {
            $table->dropColumn('es_efectivo');
        });
        DB::statement('DROP INDEX IF EXISTS medios_pago_un_efectivo');
        Schema::table('medios_pago', function (Blueprint $table) {
            $table->dropColumn('es_efectivo');
        });
    }
};
