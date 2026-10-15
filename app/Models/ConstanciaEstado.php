<?php

namespace App\Models;

/**
 * Constancia de estado del turno: la emite el profesional del turno o Supervisión médica. Solo hechos del turno
 * (se presentó, se retiró...): sin diagnóstico ni datos clínicos.
 */
class ConstanciaEstado extends Constancia
{
    protected $table = 'constancias_estado';

    /** Estados del turno que dejan un hecho para certificar: se presentó y fue atendido, no se presentó, o se retiró. */
    public const ESTADOS_CON_CONSTANCIA = [Estado::ATENDIDO, Estado::AUSENTE, Estado::RETIRADO];

    public static function moduloEstado(): string
    {
        return 'CONSTANCIAS_ESTADO';
    }

    public static function prefijo(): string
    {
        return 'CE-';
    }

    public static function nombreDelTipo(): string
    {
        return 'Constancia de estado del turno';
    }

    public static function motivoDeNoAplicar(Turno $turno): ?string
    {
        if (! in_array($turno->estado?->codigo, self::ESTADOS_CON_CONSTANCIA, true)) {
            return 'La constancia de estado es solo para turnos atendidos, con ausencia o con un retiro o rechazo.';
        }

        return $turno->tieneEstado(Estado::RETIRADO) && $turno->salida === null ? 'El turno retirado no tiene la salida registrada.' : null;
    }
}
