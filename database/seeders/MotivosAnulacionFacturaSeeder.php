<?php

namespace Database\Seeders;

use App\Models\Estado;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Los tres motivos de anulación de factura de referencia (docs/facturacion.md), con su código. Si ya hay uno con ese código no lo
 * toca (se pudo renombrar o desactivar desde la pantalla); si hay uno sin código con el mismo nombre, lo adopta. Idempotente. Con
 * DB::table, para que la migración que lo llama no falle en `migrate --pretend`.
 */
class MotivosAnulacionFacturaSeeder extends Seeder
{
    /** codigo => [nombre, requiere detalle]. */
    public const REFERENCIA = [
        'DATOS_CLIENTE' => ['Error en los datos del cliente', false],
        'PROCEDIMIENTO_MONTO' => ['Error en el procedimiento o el monto', false],
        'OTRO' => ['Otro (detallar)', true],
    ];

    public function run(): void
    {
        foreach (self::REFERENCIA as $codigo => [$nombre, $requiereDetalle]) {
            if (DB::table('motivos_anulacion_factura')->where('codigo', $codigo)->exists()) {
                continue;
            }
            $existente = DB::table('motivos_anulacion_factura')->whereNull('codigo')->where('nombre', $nombre)->value('id');
            if ($existente !== null) {
                DB::table('motivos_anulacion_factura')->where('id', $existente)->update(['codigo' => $codigo, 'updated_at' => now()]);

                continue;
            }
            DB::table('motivos_anulacion_factura')->insert([
                'nombre' => $nombre,
                'codigo' => $codigo,
                'requiere_detalle' => $requiereDetalle,
                'estado_id' => DB::table('estados')->where('codigo', Estado::ACTIVO)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
