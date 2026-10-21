<?php

namespace App\Support\Repartos;

use App\Exceptions\AccionRechazada;

/**
 * Porcentajes del reparto (docs/reparto-economico.md): de 0 a 100 con hasta dos decimales. Se calculan en centésimas enteras
 * (35,5 % = 3550) para no arrastrar errores de coma flotante.
 */
final class Porcentaje
{
    /** Lo que se acepta en un formulario: "35", "35,5", "35.50". */
    public const REGEX = '/^\d{1,3}([.,]\d{1,2})?$/';

    public const MENSAJE = 'Escriba un porcentaje de 0 a 100, con hasta dos decimales (por ejemplo, 35 o 35,5).';

    /** Del formulario ("35,5") a lo que se guarda ("35.50"). */
    public static function desdeTexto(string $texto): string
    {
        return number_format((float) str_replace(',', '.', trim($texto)), 2, '.', '');
    }

    /** Lo que se guarda ("35.50"), o AccionRechazada si no es de 0 a 100 con hasta dos decimales. */
    public static function validado(string $texto): string
    {
        if (! preg_match(self::REGEX, trim($texto)) || (float) self::desdeTexto($texto) > 100) {
            throw new AccionRechazada(self::MENSAJE);
        }

        return self::desdeTexto($texto);
    }

    /** En centésimas enteras: "35.50" -> 3550. */
    public static function centesimas(string|int|float $porcentaje): int
    {
        return (int) round(((float) $porcentaje) * 100);
    }

    /** De centésimas a lo que se guarda: 3550 -> "35.50". */
    public static function desdeCentesimas(int $centesimas): string
    {
        return number_format($centesimas / 100, 2, '.', '');
    }

    /** "35 %", "35,5 %" o "12,25 %". */
    public static function texto(string|int|float|null $porcentaje): string
    {
        if ($porcentaje === null) {
            return '—';
        }
        $texto = rtrim(rtrim(number_format((float) $porcentaje, 2, ',', '.'), '0'), ',');

        return "{$texto} %";
    }

    /**
     * El monto que le toca a un porcentaje, redondeado al guaraní más cercano (la mitad, hacia arriba).
     *
     * @param  int  $monto  guaraníes enteros
     */
    public static function montoDe(int $monto, int $centesimas): int
    {
        return intdiv($monto * $centesimas + 5000, 10000);
    }
}
