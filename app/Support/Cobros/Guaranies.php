<?php

namespace App\Support\Cobros;

/**
 * Montos en guaraníes enteros cargados en un formulario de la caja o del cobro: "150000" o "150.000" (docs/cobros-caja.md).
 */
final class Guaranies
{
    public const MAXIMO = 999_999_999_999;

    public const FORMATO = '/^(\d{1,3}(\.\d{3})+|\d{1,12})$/';

    public const MENSAJE = 'Escriba el monto en guaraníes, sin decimales (por ejemplo, 150000 o 150.000).';

    /** Reglas de validación de un monto (de 0 en adelante si se admite cero; si no, mayor que cero). */
    public static function regla(bool $admiteCero = false, bool $requerido = true): array
    {
        return [$requerido ? 'required' : 'nullable', 'string', 'regex:'.self::FORMATO, function (string $atributo, mixed $valor, \Closure $falla) use ($admiteCero) {
            $monto = self::entero((string) $valor);
            if ($monto > self::MAXIMO || (! $admiteCero && $monto < 1)) {
                $falla($admiteCero ? 'El monto no es válido.' : 'El monto debe ser mayor que cero.');
            }
        }];
    }

    public static function entero(?string $texto): int
    {
        return (int) str_replace('.', '', trim((string) $texto));
    }
}
