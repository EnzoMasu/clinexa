<?php

namespace App\Exceptions;

/**
 * Intento de un usuario de cambiar sus propios perfiles de acceso (asignarse o quitarse uno). Lo rechazan
 * UsuarioController y el modelo de la pivote (UsuarioPerfil); en un pedido web se vuelve con el aviso.
 */
class PerfilesPropios extends AccionRechazada
{
    public const MENSAJE = 'No puede cambiar su propio perfil de acceso.';

    public function __construct()
    {
        parent::__construct(self::MENSAJE);
    }
}
