<?php

namespace App\Exceptions;

use LogicException;

/**
 * Intento de modificar una receta emitida o anulada (o sus renglones), o de hacer una transición de
 * estado que no existe. Es un error de programación: las pantallas nunca lo ofrecen.
 */
class RecetaInmutable extends LogicException
{
    public static function contenido(): self
    {
        return new self('Una receta emitida o anulada no se modifica. Para corregirla, anúlela y emita otra.');
    }

    public static function renglones(): self
    {
        return new self('Los medicamentos de una receta emitida o anulada no se modifican.');
    }

    public static function transicion(string $desde, string $hacia): self
    {
        return new self("Una receta no puede pasar de {$desde} a {$hacia}.");
    }

    public static function borrado(): self
    {
        return new self('Las recetas no se borran: se anulan.');
    }
}
