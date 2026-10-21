<?php

namespace App\Exceptions;

use LogicException;

/**
 * Intento de editar o borrar una tarifa, una prestación, un acuerdo o un reparto fuera de lo permitido
 * (docs/tarifas-prestaciones.md, docs/reparto-economico.md). Es un error de
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

    public static function acuerdo(): self
    {
        return new self('Un acuerdo de reparto no se edita: para cambiar el porcentaje, se cierra el vigente y se abre otro.');
    }

    public static function reparto(): self
    {
        return new self('Un reparto solo se completa (una vez) o se anula; sus líneas no cambian.');
    }

    public static function borrado(): self
    {
        return new self('Las tarifas, las prestaciones, los acuerdos y los repartos no se borran.');
    }
}
