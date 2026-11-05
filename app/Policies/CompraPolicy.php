<?php

namespace App\Policies;

use App\Models\Compra;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Quién escribe en una compra (docs/compras-gastos.md). El permiso de COMPRAS lo controla la ruta; acá rige el estado: solo el
 * borrador (PENDIENTE) se edita, confirmada o anulada es de solo lectura. Confirmar: nunca lo que cargó uno mismo. Anular: con
 * DESACTIVAR sobre COMPRAS (Gerencia y Administrador); además, quien cargó un borrador y puede editarlo lo descarta. El estado de la
 * compra lo controla Compras\FlujoCompra con la fila bloqueada.
 */
class CompraPolicy
{
    public const SOLO_BORRADOR = 'Solo se edita una compra en borrador.';

    public function update(User $usuario, Compra $compra): Response
    {
        return $compra->esBorrador() ? Response::allow() : Response::deny(self::SOLO_BORRADOR);
    }

    public const PROPIA = 'No puede confirmar una compra que cargó usted.';

    public const SIN_PERMISO_ANULAR = 'No tiene permiso para anular esta compra.';

    public function confirmar(User $usuario, Compra $compra): Response
    {
        return (int) $compra->registrada_por === (int) $usuario->id ? Response::deny(self::PROPIA) : Response::allow();
    }

    public function anular(User $usuario, Compra $compra): Response
    {
        $propioBorrador = $compra->esBorrador() && (int) $compra->registrada_por === (int) $usuario->id && $usuario->tienePermiso('COMPRAS', 'EDITAR');

        return $usuario->tienePermiso('COMPRAS', 'DESACTIVAR') || $propioBorrador ? Response::allow() : Response::deny(self::SIN_PERMISO_ANULAR);
    }
}
