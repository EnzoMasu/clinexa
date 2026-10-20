<?php

namespace App\Exceptions;

use LogicException;

/**
 * Intento de editar o borrar una tarifa o una prestación fuera de lo permitido (docs/tarifas-prestaciones.md). Es un error de
 * programación: las pantallas nunca lo ofrecen.
 */
class RegistroEconomicoInmutable extends LogicException
{
    public static function tarifa(): self
    {
        return new self('Una tarifa no se edita: para cambiar el monto, se cierra la vigente y se abre otra.');
    }

    public static function prestacion(): self
    {
        return new self('Una prestación solo se tarifa (una vez) o se anula; el resto no cambia.');
    }

    public static function borrado(): self
    {
        return new self('Las tarifas y las prestaciones no se borran.');
    }
}
