<?php

namespace App\Support\Repartos;

use App\Models\Equipo;
use App\Models\LineaReparto;
use App\Models\Prestacion;
use App\Support\Fecha;

/**
 * El cálculo del reparto de una prestación con monto (docs/reparto-economico.md), sin escribir nada. Usa los acuerdos que se
 * aplican a su FECHA DE SERVICIO:
 *
 * - profesional: el porcentaje de su acuerdo (el del procedimiento o, si no hay, el general);
 * - equipo: si el procedimiento usa un equipo ACTIVO, el porcentaje del acuerdo del equipo, para su dueño;
 * - clínica: 100 - profesional - equipo.
 *
 * Porcentaje sobre el monto tal cual (el IVA es de Facturación). El profesional y el equipo se redondean al guaraní más cercano
 * y la clínica recibe el resto: la suma es exacta. Si falta un acuerdo o los porcentajes superan 100, devuelve el motivo y
 * ninguna línea.
 */
final class CalcularReparto
{
    /**
     * @return array{motivo: ?string, equipo: ?Equipo, lineas: list<array<string, mixed>>}
     */
    public static function para(Prestacion $prestacion): array
    {
        $dia = $prestacion->fecha_servicio->format('Y-m-d');
        $monto = (int) round((float) $prestacion->monto);
        $equipo = Equipo::delProcedimiento($prestacion->procedimiento_id);
        $acuerdoProfesional = BuscarAcuerdo::profesional($prestacion->profesional_id, $prestacion->procedimiento_id, $dia);
        $acuerdoEquipo = $equipo ? BuscarAcuerdo::equipo($equipo->id, $dia) : null;

        $faltan = array_filter([
            $acuerdoProfesional ? null : 'el acuerdo del profesional',
            $equipo && ! $acuerdoEquipo ? "el acuerdo del equipo {$equipo->nombre}" : null,
        ]);
        if ($faltan !== []) {
            return ['motivo' => 'Falta '.implode(' y ', $faltan).' para el '.Fecha::mostrar($dia).'.', 'equipo' => $equipo, 'lineas' => []];
        }

        $profesional = Porcentaje::centesimas($acuerdoProfesional->porcentaje);
        $deEquipo = $acuerdoEquipo ? Porcentaje::centesimas($acuerdoEquipo->porcentaje) : 0;
        $clinica = 10000 - $profesional - $deEquipo;
        if ($clinica < 0) {
            return ['motivo' => 'Los porcentajes suman '.Porcentaje::texto(Porcentaje::desdeCentesimas($profesional + $deEquipo)).': superan el 100 %.',
                'equipo' => $equipo, 'lineas' => []];
        }

        $montoProfesional = Porcentaje::montoDe($monto, $profesional);
        // Con los porcentajes en 100 justo, los dos redondeos hacia arriba podrían pasarse por un guaraní: se ajusta el del equipo.
        $montoEquipo = min(Porcentaje::montoDe($monto, $deEquipo), $monto - $montoProfesional);
        $acuerdos = ['acuerdo_profesional_id' => $acuerdoProfesional->id, 'acuerdo_equipo_id' => $acuerdoEquipo?->id];

        return ['motivo' => null, 'equipo' => $equipo, 'lineas' => array_values(array_filter([
            ['beneficiario' => LineaReparto::CLINICA, 'porcentaje' => Porcentaje::desdeCentesimas($clinica),
                'monto' => $monto - $montoProfesional - $montoEquipo, ...$acuerdos],
            ['beneficiario' => LineaReparto::PROFESIONAL, 'profesional_id' => $prestacion->profesional_id,
                'porcentaje' => Porcentaje::desdeCentesimas($profesional), 'monto' => $montoProfesional, 'acuerdo_profesional_id' => $acuerdoProfesional->id],
            $acuerdoEquipo ? ['beneficiario' => LineaReparto::EQUIPO, 'proveedor_id' => $equipo->proveedor_id, 'equipo_id' => $equipo->id,
                'porcentaje' => Porcentaje::desdeCentesimas($deEquipo), 'monto' => $montoEquipo, 'acuerdo_equipo_id' => $acuerdoEquipo->id] : null,
        ]))];
    }
}
