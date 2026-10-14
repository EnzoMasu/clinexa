<?php

namespace App\Exceptions;

use LogicException;

/**
 * Intento de modificar una adenda o una versión de una consulta: se agregan una vez y no se editan ni se borran.
 * Es un error de programación: las pantallas nunca lo ofrecen.
 */
class RegistroInmutable extends LogicException
{
    public function __construct()
    {
        parent::__construct('Este registro no se modifica ni se elimina.');
    }
}
