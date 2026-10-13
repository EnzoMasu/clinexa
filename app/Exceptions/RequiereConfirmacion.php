<?php

namespace App\Exceptions;

/**
 * La acción se puede hacer, pero solo confirmándola (p. ej. atender una urgencia con otra consulta en curso). El
 * mensaje es la pregunta para el usuario; no se guardó nada.
 */
class RequiereConfirmacion extends AccionRechazada {}
