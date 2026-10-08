<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pantallas con contenido clínico: que el navegador (y cualquier intermediario) no las guarde.
 * Laravel responde por defecto "no-cache, private", que igual permite guardarlas en disco (solo
 * obliga a revalidar): con "no-store" no quedan en la caché ni se ven con "Atrás" tras cerrar sesión.
 * nosniff: el navegador respeta el tipo de contenido declarado.
 */
class SinAlmacenar
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        $respuesta->headers->set('Cache-Control', 'no-store, private');
        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');

        return $respuesta;
    }
}
