<?php

namespace App\Models;

use App\Exceptions\PerfilesPropios;
use App\Support\Auditoria;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Auth;

/**
 * Fila de usuario_perfil (un perfil asignado a un usuario). Las relaciones User::perfiles y
 * PerfilAcceso::users la usan como pivote, así que attach, detach y sync pasan por acá.
 *
 * Regla: en un pedido web nadie cambia sus PROPIOS perfiles (asignarse ni quitarse uno), por ningún camino.
 * Lo rechaza también UsuarioController, con el mismo mensaje. Desde la consola (crear el primer
 * administrador, seeders) no hay usuario logueado y no aplica.
 */
class UsuarioPerfil extends Pivot
{
    protected $table = 'usuario_perfil';

    protected static function booted(): void
    {
        $propio = function (UsuarioPerfil $fila) {
            if (Auditoria::enPedidoWeb() && Auth::id() !== null && (int) Auth::id() === (int) $fila->usuario_id) {
                throw new PerfilesPropios;
            }
        };

        static::creating($propio);
        static::updating($propio);
        static::deleting($propio);
    }
}
