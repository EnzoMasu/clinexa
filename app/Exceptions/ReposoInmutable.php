<?php

namespace App\Exceptions;

use LogicException;

/**
 * Intento de modificar o borrar un certificado de reposo emitido o anulado, o de hacer una transición de estado que no
 * existe. Es un error de programación: las pantallas nunca lo ofrecen.
 */
class ReposoInmutable extends LogicException
{
    public static function contenido(): self
    {
        return new self('Un certificado de reposo emitido o anulado no se modifica. Para corregirlo, anúlelo y emita otro.');
    }

    public static function transicion(): self
    {
        return new self('Un certificado de reposo emitido solo puede pasar a anulado.');
    }

    public static function borrado(): self
    {
        return new self('Los certificados de reposo no se borran: se anulan.');
    }
}
