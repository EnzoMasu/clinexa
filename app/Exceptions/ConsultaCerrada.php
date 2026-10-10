<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Intento de escribir sobre una consulta cerrada (FINALIZADA o ANULADA) o sobre algo que cuelga de ella.
 * Una consulta cerrada queda llaveada: no se modifica, no se elimina y no se desactiva. En un pedido web
 * responde 409 con el mensaje; desde otro código (servicio, tinker) es una excepción igual.
 */
class ConsultaCerrada extends HttpException
{
    public const MENSAJE = 'La consulta está cerrada y no puede modificarse.';

    public function __construct()
    {
        parent::__construct(409, self::MENSAJE);
    }
}
