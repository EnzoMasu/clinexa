<?php

use App\Models\PerfilAcceso;
use App\Support\PerfilesPredefinidos;
use Database\Seeders\MediosPagoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobante de los cobros (docs/cobros-caja.md). Aditiva:
 *
 * - `medios_pago.requiere_comprobante` (el medio exige el número de comprobante al cobrar) y `nombre_comprobante` (cómo se llama en
 *   pantalla: código, voucher, boleta, número de cheque). El efectivo nunca lo exige (en PostgreSQL, `medios_pago_efectivo_sin_comprobante`).
 * - El comprobante es la `referencia` que ya tenía cada línea de cobro: no se migra ni se toca ningún dato; los cobros anteriores quedan
 *   como están. La regla rige solo para cobros nuevos.
 * - Los medios de referencia DEPOSITO y CHEQUE (los carga MediosPagoSeeder, que también marca Transferencia y Tarjeta) y la acción
 *   EDITAR sobre COBROS para Caja (corregir el comprobante). No lee filas durante `migrate --pretend`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medios_pago', function (Blueprint $table) {
            $table->boolean('requiere_comprobante')->default(false);
            $table->string('nombre_comprobante', 40)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE medios_pago ADD CONSTRAINT medios_pago_efectivo_sin_comprobante CHECK (NOT (es_efectivo AND requiere_comprobante))');
        }

        // Solo en una instalación existente (con módulos): en una nueva los cargan los seeders.
        if (DB::table('modulos_sistema')->exists()) {
            (new MediosPagoSeeder)->run();
        }
        if (PerfilAcceso::where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
            foreach (PerfilesPredefinidos::PERMISOS_COMPROBANTE_COBRO as $modulo => $accionesPorPerfil) {
                if (DB::table('modulos_sistema')->where('codigo', $modulo)->exists()) {
                    PerfilesPredefinidos::sumarModulo($modulo, $accionesPorPerfil);
                }
            }
        }
    }

    /** Los medios y el permiso quedan; solo se quitan las columnas. */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE medios_pago DROP CONSTRAINT IF EXISTS medios_pago_efectivo_sin_comprobante');
        }
        Schema::table('medios_pago', function (Blueprint $table) {
            $table->dropColumn(['requiere_comprobante', 'nombre_comprobante']);
        });
    }
};
