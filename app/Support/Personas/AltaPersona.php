<?php

namespace App\Support\Personas;

use App\Models\Persona;

/**
 * Alta de una persona con los datos ya validados por PersonaRequest. La usan la pantalla de Personas
 * y el alta rápida del formulario de Proveedor: en los dos casos queda auditada como alta de Persona
 * (Persona es Auditable), con el usuario que la cargó.
 */
final class AltaPersona
{
    public static function crear(array $datos): Persona
    {
        return Persona::create($datos);
    }
}
