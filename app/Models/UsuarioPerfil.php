<?php

namespace App\Models;

use App\Exceptions\AccionRechazada;
use App\Exceptions\AdministradorPrincipalInamovible;
use App\Exceptions\PerfilesPropios;
use App\Support\AdministradorPrincipal;
use App\Support\Auditoria;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Auth;

/**
 * Fila de usuario_perfil (un perfil asignado a un usuario). Las relaciones User::perfiles y
 * PerfilAcceso::users la usan como pivote, así que attach, detach y sync pasan por acá.
 *
 * Reglas, en un pedido web y por ningún camino (también las aplica UsuarioController, con los mismos mensajes):
 * - los perfiles del administrador principal no se tocan, tampoco él mismo;
 * - nadie cambia sus PROPIOS perfiles (asignarse ni quitarse uno);
 * - el perfil Administrador solo lo asigna o lo quita el administrador principal
 *   (AdministradorPrincipal::SOLO_EL_PRINCIPAL_ASIGNA_ADMINISTRADOR).
 * Desde la consola (crear el primer administrador, seeders, traspaso) no aplican.
 */
class UsuarioPerfil extends Pivot
{
    protected $table = 'usuario_perfil';

    protected static function booted(): void
    {
        $guardas = function (UsuarioPerfil $fila) {
            if (! Auditoria::enPedidoWeb()) {
                return;
            }
            // Los perfiles del administrador principal no se tocan, tampoco él mismo.
            if (User::whereKey($fila->usuario_id)->where('administrador_principal', true)->exists()) {
                throw new AdministradorPrincipalInamovible;
            }
            if (Auth::id() !== null && (int) Auth::id() === (int) $fila->usuario_id) {
                throw new PerfilesPropios;
            }
            // El perfil Administrador solo lo asigna o lo quita el administrador principal.
            if (! AdministradorPrincipal::puedeAsignarAdministrador()
                && PerfilAcceso::whereKey($fila->perfil_acceso_id)->where('codigo', PerfilAcceso::ADMINISTRADOR)->exists()) {
                throw new AccionRechazada(AdministradorPrincipal::SOLO_EL_PRINCIPAL);
            }
        };

        static::creating($guardas);
        static::updating($guardas);
        static::deleting($guardas);
    }
}
