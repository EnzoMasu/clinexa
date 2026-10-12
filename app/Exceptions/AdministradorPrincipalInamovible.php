<?php

namespace App\Exceptions;

/**
 * Intento de modificar al administrador principal: su estado, sus perfiles, su persona (salvo él mismo, sus
 * datos) o mandarle una invitación o un restablecimiento desde Usuarios. Lo rechazan User, Persona,
 * UsuarioPerfil y PerfilAcceso en cualquier pedido web; en la web se vuelve con el aviso (bootstrap/app.php).
 */
class AdministradorPrincipalInamovible extends AccionRechazada
{
    public const MENSAJE = 'Es el administrador principal y no puede modificarse.';

    public function __construct()
    {
        parent::__construct(self::MENSAJE);
    }
}
