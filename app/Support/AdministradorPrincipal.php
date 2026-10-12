<?php

namespace App\Support;

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\PerfilAcceso;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * El administrador principal (users.administrador_principal): un único usuario inamovible. En un pedido web
 * nadie, tampoco él mismo, cambia su estado ni sus perfiles ni desactiva su persona; los datos de su persona
 * solo los edita él; desde Usuarios no se le manda invitación ni restablecimiento. La consola queda afuera,
 * como en las otras guardas (la marca se mueve con clinexa:traspasar-principal). Ver docs/perfiles.md.
 */
final class AdministradorPrincipal
{
    /**
     * Solo el administrador principal asigna o quita el perfil Administrador a otros usuarios. Para que lo
     * haga cualquier Administrador, cambiar a false (y actualizar docs/perfiles.md y los tests).
     */
    public const SOLO_EL_PRINCIPAL_ASIGNA_ADMINISTRADOR = true;

    public const SOLO_EL_PRINCIPAL = 'Solo el administrador principal puede asignar o quitar el perfil Administrador.';

    public const MARCA_SOLO_POR_CONSOLA = 'La marca de administrador principal solo se cambia con el comando clinexa:traspasar-principal.';

    public const YA_HAY_OTRO = 'Ya hay un administrador principal: a lo sumo puede haber uno.';

    /** Las guardas valen en los pedidos web (formularios, servicios y modelos); la consola queda afuera. */
    public static function aplica(): bool
    {
        return Auditoria::enPedidoWeb();
    }

    /** Si el usuario logueado es el administrador principal. */
    public static function esElLogueado(): bool
    {
        $usuario = Auth::user();

        return $usuario instanceof User && $usuario->esAdministradorPrincipal();
    }

    /** Si quien hace el pedido puede asignar o quitar el perfil Administrador. */
    public static function puedeAsignarAdministrador(): bool
    {
        return ! self::SOLO_EL_PRINCIPAL_ASIGNA_ADMINISTRADOR || ! self::aplica() || self::esElLogueado();
    }

    public static function actual(): ?User
    {
        return User::where('administrador_principal', true)->first();
    }

    /**
     * Traspaso (solo desde la consola): el nuevo tiene que estar ACTIVO, con su persona ACTIVA y el perfil
     * Administrador activo. Mueve la marca en una transacción y deja un evento de auditoría (EDITAR del nuevo,
     * con el usuario anterior en el detalle). Devuelve el principal anterior (o null si no había).
     */
    public static function traspasar(User $nuevo): ?User
    {
        if ($nuevo->esAdministradorPrincipal()) {
            throw new AccionRechazada('Ese usuario ya es el administrador principal.');
        }
        if (! $nuevo->estaActivo() || $nuevo->persona?->estaActivo() !== true) {
            throw new AccionRechazada('El nuevo administrador principal tiene que estar activo (usuario y persona).');
        }
        if (! $nuevo->perfiles()->where('codigo', PerfilAcceso::ADMINISTRADOR)->where('estado_id', Estado::idDe(Estado::ACTIVO))->exists()) {
            throw new AccionRechazada('El nuevo administrador principal tiene que tener el perfil Administrador.');
        }

        return DB::transaction(function () use ($nuevo) {
            $anterior = User::where('administrador_principal', true)->lockForUpdate()->first();
            // Primero se quita la del anterior: el índice único parcial no admite dos en true.
            $anterior?->forceFill(['administrador_principal' => false])->save();
            $nuevo->forceFill(['administrador_principal' => true])->save();

            Auditoria::registrar(AccionAuditoria::EDITAR, $nuevo->getTable(), $nuevo->id,
                ['administrador_principal' => false], ['administrador_principal' => true],
                'Traspaso del administrador principal por consola'.($anterior ? " (antes: usuario #{$anterior->id})" : ' (no había otro)').'.',
                usuarioId: null);

            return $anterior;
        });
    }
}
