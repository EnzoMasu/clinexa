<?php

namespace App\Exceptions;

use LogicException;

/**
 * Intento de modificar o borrar una constancia emitida o anulada, o de hacer una transición de estado que no
 * existe. Es un error de programación: las pantallas nunca lo ofrecen.
 */
class ConstanciaInmutable extends LogicException
{
    public static function contenido(): self
    {
        return new self('Una constancia emitida o anulada no se modifica. Para corregirla, anúlela y emita otra.');
    }

    public static function transicion(): self
    {
        return new self('Una constancia emitida solo puede pasar a anulada.');
    }

    public static function borrado(): self
    {
        return new self('Las constancias no se borran: se anulan.');
    }
}
