<?php

namespace App\Support\Repartos;

use App\Models\AcuerdoReparto;

/**
 * El acuerdo que se aplica a un servicio (docs/reparto-economico.md), por su rango de fechas (no por el estado):
 *
 * - del profesional: el de ese procedimiento (la excepción) y, si no tiene, el general del profesional;
 * - del equipo: el del equipo.
 */
final class BuscarAcuerdo
{
    /** @param  string  $dia  aaaa-mm-dd */
    public static function profesional(int $profesionalId, ?int $procedimientoId, string $dia): ?AcuerdoReparto
    {
        $buscar = fn (?int $procedimiento) => AcuerdoReparto::query()
            ->deLaClave(AcuerdoReparto::PROFESIONAL, $profesionalId, $procedimiento, null)->aplicaA($dia)
            ->orderByDesc('vigente_desde')->first();

        return ($procedimientoId ? $buscar($procedimientoId) : null) ?? $buscar(null);
    }

    /** @param  string  $dia  aaaa-mm-dd */
    public static function equipo(int $equipoId, string $dia): ?AcuerdoReparto
    {
        return AcuerdoReparto::query()->deLaClave(AcuerdoReparto::EQUIPO, null, null, $equipoId)->aplicaA($dia)
            ->orderByDesc('vigente_desde')->first();
    }
}
