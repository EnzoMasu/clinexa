<?php

namespace App\Support\Cobros;

/**
 * Montos en guaraníes enteros cargados en un formulario de la caja o del cobro: "150000" o "150.000" (docs/cobros-caja.md).
 */
final class Guaranies
{
    public const MAXIMO = 999_999_999_999;

    public const FORMATO = '/^(\d{1,3}(\.\d{3})+|\d{1,12})$/';

    /** Lo mismo, con un signo menos opcional (lo contado en un medio que no es efectivo, al cerrar la caja). */
    public const FORMATO_CON_SIGNO = '/^-?(\d{1,3}(\.\d{3})+|\d{1,12})$/';

    public const MENSAJE = 'Escriba el monto en guaraníes, sin decimales (por ejemplo, 150000 o 150.000).';

    /**
     * Reglas de validación de un monto (de 0 en adelante si se admite cero; si no, mayor que cero). Con signo, también negativo (el
     * servicio decide en qué medio se admite).
     */
    public static function regla(bool $admiteCero = false, bool $requerido = true, bool $conSigno = false): array
    {
        return [$requerido ? 'required' : 'nullable', 'string', 'regex:'.($conSigno ? self::FORMATO_CON_SIGNO : self::FORMATO), function (string $atributo, mixed $valor, \Closure $falla) use ($admiteCero, $conSigno) {
            $monto = self::entero((string) $valor);
            if (abs($monto) > self::MAXIMO || (! $conSigno && ! $admiteCero && $monto < 1)) {
                $falla($admiteCero ? 'El monto no es válido.' : 'El monto debe ser mayor que cero.');
            }
        }];
    }

    public static function entero(?string $texto): int
    {
        return (int) str_replace('.', '', trim((string) $texto));
    }
}
