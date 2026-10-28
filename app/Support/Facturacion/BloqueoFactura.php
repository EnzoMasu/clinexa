<?php

namespace App\Support\Facturacion;

use App\Models\DetalleFactura;
use App\Models\Prestacion;

/**
 * Una prestación facturada (en una factura no anulada) no cambia de visación ni de monto, ni se anula (docs/facturacion.md): primero
 * se anula la factura (que revierte su cobro). Lo usan "Aplicar visación" y la anulación de la prestación. La firma recibida no cambia
 * el monto: sigue permitida (la necesita la liquidación de la parte de la aseguradora).
 */
final class BloqueoFactura
{
    /** El aviso, o null si la prestación no está en una factura no anulada. */
    public static function impedimento(Prestacion $prestacion): ?string
    {
        $detalle = DetalleFactura::query()->where('prestacion_id', $prestacion->id)->whereNull('liberada_en')->with('factura:id,numero_completo')->first();

        return $detalle === null ? null
            : "Esta prestación está facturada (factura {$detalle->factura->numero_completo}): no se pueden cambiar su visación ni su monto, ni anularla. Primero se anula la factura.";
    }
}
