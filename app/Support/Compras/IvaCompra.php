<?php

namespace App\Support\Compras;

use App\Models\TipoComprobanteCompra;
use App\Support\Facturacion\Iva;

/**
 * El IVA de una compra (docs/compras-gastos.md), con el mismo cálculo que la factura emitida (Facturacion\Iva): montos con IVA incluido,
 * 10 % = monto / 11 y 5 % = monto / 21, al guaraní entero (la mitad, hacia arriba); total = monto_10 + monto_5 + monto_exento. Solo
 * para un tipo de comprobante con la casilla "discrimina IVA" (hoy, la factura legal): con los demás, solo el total, sin montos por
 * tasa ni IVA (null los cinco). Nunca mira el nombre del tipo.
 */
final class IvaCompra
{
    /** @return array{monto_10: int, monto_5: int, monto_exento: int, iva_10: int, iva_5: int, total: int} */
    public static function desglose(int $monto10, int $monto5, int $montoExento): array
    {
        if ($monto10 < 0 || $monto5 < 0 || $montoExento < 0) {
            throw new \InvalidArgumentException('Los montos por tasa no pueden ser negativos.');
        }

        return [
            'monto_10' => $monto10,
            'monto_5' => $monto5,
            'monto_exento' => $montoExento,
            'iva_10' => Iva::desglose($monto10, 10)['iva'],
            'iva_5' => Iva::desglose($monto5, 5)['iva'],
            'total' => $monto10 + $monto5 + $montoExento,
        ];
    }

    /**
     * Los importes que se guardan en la compra según su tipo: con desglose si discrimina IVA, o solo el total.
     *
     * @return array{monto_10: ?int, monto_5: ?int, monto_exento: ?int, iva_10: ?int, iva_5: ?int, total: int}
     */
    public static function importes(TipoComprobanteCompra $tipo, int $monto10, int $monto5, int $montoExento, int $total): array
    {
        if ($tipo->discrimina_iva) {
            return self::desglose($monto10, $monto5, $montoExento);
        }

        return ['monto_10' => null, 'monto_5' => null, 'monto_exento' => null, 'iva_10' => null, 'iva_5' => null, 'total' => $total];
    }
}
