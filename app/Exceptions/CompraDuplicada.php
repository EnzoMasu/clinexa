<?php

namespace App\Exceptions;

use App\Models\Compra;
use DomainException;

/** El comprobante (proveedor + tipo + número) ya está en otra compra no anulada (docs/compras-gastos.md); lleva esa compra. */
class CompraDuplicada extends DomainException
{
    public function __construct(public readonly Compra $existente)
    {
        parent::__construct("Ese comprobante ya está registrado en la compra N.º {$existente->id}.");
    }
}
