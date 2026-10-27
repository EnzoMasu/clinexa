<?php

namespace Database\Seeders\Demo;

/**
 * Dígito verificador del RUC (módulo 11 de la DNIT): pesos 2 a 11 de derecha a izquierda; DV = 11 - resto si el
 * resto es mayor que 1, y 0 si no. Para dar de alta personas jurídicas demo con un RUC válido.
 */
final class Ruc
{
    public static function digito(string $base): int
    {
        $suma = 0;
        foreach (array_reverse(str_split($base)) as $posicion => $digito) {
            $suma += (int) $digito * ($posicion + 2);
        }
        $resto = $suma % 11;

        return $resto > 1 ? 11 - $resto : 0;
    }

    /** "44444401" => "44444401-7". */
    public static function conDigito(string $base): string
    {
        return $base.'-'.self::digito($base);
    }

    public static function esValido(string $ruc): bool
    {
        return (bool) preg_match('/^(\d+)-(\d)$/', $ruc, $partes) && self::digito($partes[1]) === (int) $partes[2];
    }
}
