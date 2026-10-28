<?php

namespace App\Support\Facturacion;

/**
 * El IVA de los precios con IVA incluido (docs/facturacion.md), en guaraníes enteros: 10 % = monto / 11 y 5 % = monto / 21, redondeado
 * al guaraní (la mitad, hacia arriba); gravado = monto − IVA; exenta (0), sin IVA. Se calcula por línea y los totales son la suma de las
 * líneas: la suma es exacta.
 */
final class Iva
{
    public const TASAS = [10, 5, 0];

    /** @return array{gravado: int, iva: int} */
    public static function desglose(int $monto, int $tasa): array
    {
        // round(monto / d) con la mitad hacia arriba, en enteros: floor((2·monto + d) / 2d).
        $iva = match ($tasa) {
            10 => intdiv(2 * $monto + 11, 22),
            5 => intdiv(2 * $monto + 21, 42),
            0 => 0,
            default => throw new \InvalidArgumentException("Tasa de IVA desconocida: {$tasa}."),
        };

        return ['gravado' => $monto - $iva, 'iva' => $iva];
    }

    /**
     * Los totales por tasa de unas líneas (monto, tasa).
     *
     * @param  iterable<array{monto: int, tasa: int}>  $lineas
     * @return array{gravado_10: int, iva_10: int, gravado_5: int, iva_5: int, exento: int, total: int}
     */
    public static function totales(iterable $lineas): array
    {
        $totales = ['gravado_10' => 0, 'iva_10' => 0, 'gravado_5' => 0, 'iva_5' => 0, 'exento' => 0, 'total' => 0];
        foreach ($lineas as $linea) {
            ['gravado' => $gravado, 'iva' => $iva] = self::desglose($linea['monto'], $linea['tasa']);
            match ($linea['tasa']) {
                10 => [$totales['gravado_10'] += $gravado, $totales['iva_10'] += $iva],
                5 => [$totales['gravado_5'] += $gravado, $totales['iva_5'] += $iva],
                default => $totales['exento'] += $gravado,
            };
            $totales['total'] += $linea['monto'];
        }

        return $totales;
    }
}
