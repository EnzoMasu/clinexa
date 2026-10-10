<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Formato de fechas de toda la aplicación: se muestran y se cargan como dd/mm/aaaa (y las
 * fecha-hora como dd/mm/aaaa HH:mm). En la base se guardan en ISO (aaaa-mm-dd) y las fecha-hora
 * en UTC; se muestran en la hora local de la clínica (config app.zona_horaria_local).
 */
final class Fecha
{
    public const FORMATO = 'd/m/Y';

    public const FORMATO_HORA = 'd/m/Y H:i';

    /** Hoy en la hora local de la clínica (no en UTC: entre las 21 y las 24 serían días distintos). */
    /** Ahora en la hora local de la clínica. */
    public static function ahora(): Carbon
    {
        return Carbon::now(config('app.zona_horaria_local'));
    }

    /**
     * Si ese momento (fecha y hora locales, "Y-m-d" y "H:i") ya llegó: el día ya pasó, o es hoy y la hora,
     * al minuto, es la de ahora o anterior (en punto cuenta como llegado). Regla ÚNICA de "ya es la hora /
     * ya pasó" para: los horarios libres (no se ofrece uno que ya empezó), no agendar en el pasado y marcar
     * la ausencia de un turno (solo desde su hora).
     */
    public static function yaLlego(string $fecha, string $hora): bool
    {
        $ahora = self::ahora();
        $hoy = $ahora->format('Y-m-d');

        return $fecha < $hoy || ($fecha === $hoy && substr($hora, 0, 5) <= $ahora->format('H:i'));
    }

    public static function hoy(): Carbon
    {
        return Carbon::now(config('app.zona_horaria_local'))->startOfDay();
    }

    /**
     * Para mostrar: 05/03/1990, o con hora 05/03/1990 14:30 (pasada a la hora local). Vacío si no
     * hay fecha. Las fechas sin hora no se convierten: son un día, no un instante.
     */
    public static function mostrar(CarbonInterface|string|null $fecha, bool $conHora = false): string
    {
        if ($fecha === null || $fecha === '') {
            return '';
        }

        return $conHora
            ? Carbon::parse($fecha)->setTimezone(config('app.zona_horaria_local'))->format(self::FORMATO_HORA)
            : Carbon::parse($fecha)->format(self::FORMATO);
    }

    /** Lo que se cargó en un formulario (dd/mm/aaaa) -> ISO para guardar. */
    public static function aIso(?string $fecha): ?string
    {
        return $fecha === null || $fecha === '' ? null : Carbon::createFromFormat('!'.self::FORMATO, $fecha)->format('Y-m-d');
    }

    /** Regla de validación de una fecha cargada como dd/mm/aaaa. */
    public static function regla(bool $hastaHoy = false): array
    {
        return ['date_format:'.self::FORMATO, ...($hastaHoy ? ['before_or_equal:'.self::hoy()->format(self::FORMATO)] : [])];
    }

    /**
     * Mensajes en castellano para un campo de fecha (las reglas genéricas dirían "formato d/m/Y"
     * y "anterior o igual a today").
     *
     * @return array<string, string>
     */
    public static function mensajes(string $campo): array
    {
        return [
            "{$campo}.date_format" => 'El campo :attribute debe tener el formato dd/mm/aaaa (por ejemplo, 05/03/1990).',
            "{$campo}.before_or_equal" => 'El campo :attribute no puede ser posterior a hoy.',
        ];
    }
}
