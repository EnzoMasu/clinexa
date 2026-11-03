<?php

namespace App\Policies;

use App\Models\Compra;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Quién escribe en una compra (docs/compras-gastos.md). El permiso de COMPRAS lo controla la ruta; acá rige el estado: solo el
 * borrador (PENDIENTE) se edita, confirmada o anulada es de solo lectura. Confirmar, anular y descartar se suman con el flujo.
 */
class CompraPolicy
{
    public const SOLO_BORRADOR = 'Solo se edita una compra en borrador.';

    public function update(User $usuario, Compra $compra): Response
    {
        return $compra->esBorrador() ? Response::allow() : Response::deny(self::SOLO_BORRADOR);
    }
}
