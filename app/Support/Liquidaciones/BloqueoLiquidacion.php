<?php

namespace App\Support\Liquidaciones;

use App\Models\LineaLiquidacion;
use App\Models\Prestacion;

/**
 * Una prestación que está en una liquidación no anulada no cambia de visación, firma ni monto, ni se anula
 * (docs/liquidaciones-aseguradoras.md). En preparación, se quita primero de la liquidación; pendiente de aprobación, se pide que la
 * devuelvan; aprobada, queda bloqueada. Lo usan "Aplicar visación", la firma recibida y la anulación de la prestación.
 */
final class BloqueoLiquidacion
{
    /** El aviso, o null si la prestación no está en ninguna liquidación no anulada. */
    public static function impedimento(Prestacion $prestacion): ?string
    {
        $linea = LineaLiquidacion::query()->where('prestacion_id', $prestacion->id)->whereNull('liberada_en')->with('liquidacion')->first();
        if ($linea === null) {
            return null;
        }
        $liquidacion = $linea->liquidacion;

        return match (true) {
            $liquidacion->enPreparacion() => "Esta prestación está en la liquidación N.º {$liquidacion->id}, en preparación: quítela de la liquidación antes de cambiarla.",
            $liquidacion->pendienteDeAprobacion() => "Esta prestación está en la liquidación N.º {$liquidacion->id}, pendiente de aprobación: pida que la devuelvan y quítela antes de cambiarla.",
            default => "Esta prestación está en la liquidación N.º {$liquidacion->id}, aprobada: no se pueden cambiar su visación, su firma ni su monto.",
        };
    }
}
