<?php

namespace App\Enums;

/** Tipo de un diagnóstico de la consulta (diagnosticos.tipo). */
enum TipoDiagnostico: string
{
    case PRESUNTIVO = 'PRESUNTIVO';
    case CONFIRMADO = 'CONFIRMADO';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PRESUNTIVO => 'Presuntivo',
            self::CONFIRMADO => 'Confirmado',
        };
    }
}
