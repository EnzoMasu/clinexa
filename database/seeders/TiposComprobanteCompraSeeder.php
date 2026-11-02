<?php

namespace Database\Seeders;

use App\Models\Estado;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Los cuatro tipos de comprobante de compra iniciales (docs/compras-gastos.md), con sus casillas de reglas. Las reglas de Compras
 * miran solo las casillas, nunca el nombre ni el id. Si ya hay uno con ese nombre no lo toca (se pudo editar o desactivar desde la
 * pantalla). Idempotente. Con DB::table, para que la migración que lo llama no falle en `migrate --pretend`.
 */
class TiposComprobanteCompraSeeder extends Seeder
{
    /** nombre => casillas. */
    public const INICIALES = [
        'Factura legal' => ['requiere_numero' => true, 'numero_formato_legal' => true, 'requiere_timbrado' => true, 'requiere_fecha_comprobante' => true, 'discrimina_iva' => true],
        'Ticket' => ['requiere_numero' => true, 'numero_formato_legal' => false, 'requiere_timbrado' => false, 'requiere_fecha_comprobante' => true, 'discrimina_iva' => false],
        'Recibo' => ['requiere_numero' => true, 'numero_formato_legal' => false, 'requiere_timbrado' => false, 'requiere_fecha_comprobante' => true, 'discrimina_iva' => false],
        'Sin comprobante' => ['requiere_numero' => false, 'numero_formato_legal' => false, 'requiere_timbrado' => false, 'requiere_fecha_comprobante' => false, 'discrimina_iva' => false],
    ];

    public function run(): void
    {
        $activo = DB::table('estados')->where('codigo', Estado::ACTIVO)->value('id');
        foreach (self::INICIALES as $nombre => $casillas) {
            if (DB::table('tipos_comprobante_compra')->where('nombre', $nombre)->exists()) {
                continue;
            }
            DB::table('tipos_comprobante_compra')->insert([
                'nombre' => $nombre, ...$casillas, 'estado_id' => $activo, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
