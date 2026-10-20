<?php

namespace App\Support\Tarifas;

use App\Models\Tarifa;

/**
 * La tarifa que se aplica a un servicio (docs/tarifas-prestaciones.md): la del profesional para ese día y, si no tiene, la
 * general del procedimiento. Se elige por el rango de fechas (no por el estado): una tarifa ya cerrada sigue valiendo para
 * los días de su rango.
 */
final class BuscarTarifa
{
    /** @param  string  $dia  aaaa-mm-dd */
    public static function para(?int $procedimientoId, ?int $profesionalId, string $dia): ?Tarifa
    {
        if ($procedimientoId === null) {
            return null;
        }

        $buscar = fn (?int $profesional) => Tarifa::query()->deLaClave($procedimientoId, $profesional)->aplicaA($dia)
            ->orderByDesc('vigente_desde')->first();

        return ($profesionalId ? $buscar($profesionalId) : null) ?? $buscar(null);
    }
}
