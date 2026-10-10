<?php

namespace App\Exceptions;

/**
 * Intento de dejar el sistema sin administrador: quitarle el perfil Administrador, desactivar o bloquear al
 * último usuario activo que lo tiene (o desactivar su persona). Lo rechazan User, Persona y la asignación de
 * perfiles; en un pedido web se vuelve a la pantalla con el aviso (bootstrap/app.php).
 */
class UltimoAdministrador extends AccionRechazada
{
    public const MENSAJE = 'No se puede: es el último usuario activo con el perfil Administrador. Asigne antes ese perfil a otro usuario.';

    public function __construct()
    {
        parent::__construct(self::MENSAJE);
    }
}
