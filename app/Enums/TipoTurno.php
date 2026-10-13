<?php

namespace App\Enums;

/**
 * Tipo de turno (turnos.tipo). NORMAL tiene horario y consultorio; ENTRE_PACIENTES y URGENCIA no tienen
 * horas (no ocupan horarios ni entran en las restricciones EXCLUDE: su rango es NULL). Ver
 * docs/agenda-urgencias.md.
 */
enum TipoTurno: string
{
    case NORMAL = 'NORMAL';
    case ENTRE_PACIENTES = 'ENTRE_PACIENTES';
    case URGENCIA = 'URGENCIA';

    public function etiqueta(): string
    {
        return match ($this) {
            self::NORMAL => 'Con horario',
            self::ENTRE_PACIENTES => 'Entre pacientes',
            self::URGENCIA => 'Urgencia',
        };
    }

    public function tieneHorario(): bool
    {
        return $this === self::NORMAL;
    }
}
