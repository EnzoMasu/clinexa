<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso en rutas: ->middleware('permiso:PERSONAS,CREAR'). El módulo es el código de modulos_sistema.
 */
class VerificarPermiso
{
    public function handle(Request $request, Closure $next, string $modulo, string $accion): Response
    {
        abort_unless(
            $request->user()?->tienePermiso($modulo, $accion),
            403,
            'No tenés permiso para acceder a esta sección.'
        );

        return $next($request);
    }
}
