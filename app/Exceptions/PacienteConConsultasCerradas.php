<?php

namespace App\Exceptions;

/**
 * Intento de desactivar a una paciente con consultas cerradas (FINALIZADA), o a su Persona. La historia
 * clínica cerrada queda llaveada: la paciente no se desactiva. En un pedido web se vuelve a la pantalla
 * con el aviso (bootstrap/app.php); desde otro código es una excepción.
 */
class PacienteConConsultasCerradas extends AccionRechazada
{
    public const MENSAJE = 'La paciente tiene consultas cerradas y no puede desactivarse.';

    public function __construct()
    {
        parent::__construct(self::MENSAJE);
    }
}
