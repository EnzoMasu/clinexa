<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso en rutas: ->middleware('permiso.alguno:PREPARACION.EDITAR,HISTORIA_CLINICA.CREAR'). Deja pasar si el
 * usuario tiene AL MENOS UNO de los permisos (MODULO.ACCION). Es para las rutas que usan dos perfiles
 * distintos (la preparación la carga enfermería con PREPARACION o el profesional con HISTORIA_CLINICA); la
 * regla fina la decide la policy en el controlador.
 *
 * Va antes de buscar el registro de la URL, como el middleware de permisos: sin ninguno de los permisos
 * es 403, exista o no el registro (un 404 revelaría qué ids existen). No registra lecturas.
 */
class VerificarAlgunPermiso
{
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $usuario = $request->user();
        $alguno = $usuario && collect($permisos)->contains(function (string $permiso) use ($usuario) {
            [$modulo, $accion] = explode('.', $permiso, 2) + [1 => ''];

            return $usuario->tienePermiso($modulo, $accion);
        });

        abort_unless($alguno, 403, 'No tiene permiso para acceder a esta sección.');

        return $next($request);
    }
}
