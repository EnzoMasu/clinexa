<?php

namespace App\Support;

/**
 * RUC de Paraguay: 6 a 8 dígitos, guion y un dígito verificador (DV). No se soportan los RUC antiguos que
 * terminan en letra.
 *
 * Dígito verificador (módulo 11 de la DNIT): los dígitos de la base, de derecha a izquierda, se multiplican por
 * los pesos 2, 3, ... 11 (si hay más de 10 dígitos, los pesos vuelven a empezar en 2); S = suma de los productos;
 * DV = 11 - (S mod 11) si el resto es mayor que 1, y 0 si no. Ver docs/ruc.md.
 */
final class Ruc
{
    public const FORMATO = '/^(\d{6,8})-(\d)$/';

    public static function digito(string $base): int
    {
        $suma = 0;
        foreach (array_reverse(str_split($base)) as $posicion => $digito) {
            $suma += (int) $digito * (($posicion % 10) + 2);
        }
        $resto = $suma % 11;

        return $resto > 1 ? 11 - $resto : 0;
    }

    /** "44444401" => "44444401-7". */
    public static function conDigito(string $base): string
    {
        return $base.'-'.self::digito($base);
    }

    /** Tiene el formato 6 a 8 dígitos, guion y DV (no mira que el DV sea el correcto). */
    public static function tieneFormato(string $ruc): bool
    {
        return (bool) preg_match(self::FORMATO, $ruc);
    }

    /** Formato correcto y DV que coincide con el calculado. */
    public static function esValido(string $ruc): bool
    {
        return (bool) preg_match(self::FORMATO, $ruc, $partes) && self::digito($partes[1]) === (int) $partes[2];
    }

    /** Si la validación del dígito está activa (clinexa.validar_digito_ruc, por defecto sí). */
    public static function validaDigito(): bool
    {
        return (bool) config('clinexa.validar_digito_ruc', true);
    }
}
