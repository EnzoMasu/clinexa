<?php

namespace App\Exceptions;

use LogicException;

/**
 * Intento de borrar un registro de la historia clínica (historia, consulta, anamnesis, examen físico,
 * diagnóstico, indicación, receta o renglón). Nada se elimina: se retira, se anula o se cierra. Única
 * excepción: "Quitar" un renglón de una receta en BORRADOR. Es un error de programación: las pantallas
 * nunca lo ofrecen.
 */
class RegistroClinicoNoSeBorra extends LogicException
{
    public function __construct()
    {
        parent::__construct('Los registros de la historia clínica no se borran.');
    }
}
