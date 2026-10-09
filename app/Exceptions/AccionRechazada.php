<?php

namespace App\Exceptions;

use DomainException;

/**
 * Una acción del flujo de atención que no se puede hacer en el estado actual (por ejemplo, el turno
 * cambió desde otra ventana). El mensaje es para el usuario; el controlador lo muestra y no guarda nada.
 */
class AccionRechazada extends DomainException {}
