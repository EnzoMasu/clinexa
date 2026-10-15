<?php

namespace App\Policies;

use App\Models\ConstanciaRetiro;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Constancia de retiro: la emite Recepción (CONSTANCIAS_RETIRO). Emitir pide CREAR y un turno con retiro o rechazo
 * registrado; anular pide EDITAR y una constancia emitida. Leer pide VER (lo controla la ruta).
 */
class ConstanciaRetiroPolicy
{
    public const MODULO = 'CONSTANCIAS_RETIRO';

    public function ver(User $usuario, ConstanciaRetiro $constancia): bool
    {
        return $usuario->tienePermiso(self::MODULO, 'VER');
    }

    public function emitir(User $usuario, Turno $turno): Response
    {
        if (! $usuario->tienePermiso(self::MODULO, 'CREAR')) {
            return Response::deny('No tiene permiso para emitir constancias de retiro.');
        }

        return ($motivo = ConstanciaRetiro::motivoDeNoAplicar($turno)) ? Response::deny($motivo) : Response::allow();
    }

    public function anular(User $usuario, ConstanciaRetiro $constancia): Response
    {
        if (! $usuario->tienePermiso(self::MODULO, 'EDITAR')) {
            return Response::deny('No tiene permiso para anular constancias de retiro.');
        }

        return $constancia->estaEmitida() ? Response::allow() : Response::deny('Esta constancia ya está anulada.');
    }
}
