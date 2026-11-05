<?php

namespace App\Support\Compras;

use App\Models\TipoComprobanteCompra;
use App\Support\Fecha;
use Illuminate\Support\Carbon;

/**
 * El control del comprobante de una compra (docs/compras-gastos.md), mirando SOLO las casillas del tipo (requiere número, formato
 * legal 000-000-0000000, requiere timbrado, requiere fecha del comprobante); nunca el nombre ni el id. La fecha del comprobante no
 * puede ser futura; respecto de la fecha de la compra no hay otra restricción. Lo que el tipo no exige no se guarda (queda null).
 */
final class ValidarComprobante
{
    public const FORMATO_LEGAL = '/^\d{3}-\d{3}-\d{7}$/';

    /**
     * @param  ?string  $fechaIso  la fecha del comprobante ya en aaaa-mm-dd (o null)
     * @return array<string, string> el mensaje de cada campo con problema (vacío si todo está bien)
     */
    public static function errores(TipoComprobanteCompra $tipo, ?string $numero, ?string $timbrado, ?string $fechaIso): array
    {
        $errores = [];
        $numero = self::limpio($numero);

        if ($tipo->requiere_numero) {
            if ($numero === null) {
                $errores['numero_comprobante'] = 'Indique el número del comprobante.';
            } elseif ($tipo->numero_formato_legal && ! preg_match(self::FORMATO_LEGAL, $numero)) {
                $errores['numero_comprobante'] = 'El número debe tener el formato 001-001-0000001.';
            }
        }
        if ($tipo->requiere_timbrado && self::limpio($timbrado) === null) {
            $errores['timbrado'] = 'Indique el número de timbrado del comprobante.';
        }
        if ($tipo->requiere_fecha_comprobante && $fechaIso === null) {
            $errores['fecha_comprobante'] = 'Indique la fecha del comprobante.';
        }
        if ($fechaIso !== null && Carbon::parse($fechaIso)->greaterThan(Fecha::hoy())) {
            $errores['fecha_comprobante'] = 'La fecha del comprobante no puede ser posterior a hoy.';
        }

        return $errores;
    }

    /**
     * Lo que se guarda según el tipo: número, timbrado y fecha del comprobante solo si el tipo los pide (si no, null).
     *
     * @return array{numero_comprobante: ?string, timbrado: ?string, fecha_comprobante: ?string}
     */
    public static function guardables(TipoComprobanteCompra $tipo, ?string $numero, ?string $timbrado, ?string $fechaIso): array
    {
        return [
            'numero_comprobante' => $tipo->requiere_numero ? self::limpio($numero) : null,
            'timbrado' => $tipo->requiere_timbrado ? self::limpio($timbrado) : null,
            'fecha_comprobante' => $tipo->requiere_fecha_comprobante ? $fechaIso : null,
        ];
    }

    private static function limpio(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }
}
