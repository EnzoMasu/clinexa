<?php

namespace App\Http\Middleware;

use App\Support\Grilla\Grilla;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conserva orden, filtros, búsqueda y página de cada grilla (docs/grillas.md) en la sesión del servidor
 * (nada en el navegador). Se recuperan solo al volver desde un registro de la misma sección: el pedido
 * llega sin parámetros y viene de una página que cuelga de la grilla (/admin/paises/5/edit para
 * /admin/paises), como "Cancelar", "Volver" o la redirección después de guardar. Desde el menú (que no
 * manda Referer) o desde otra sección, la grilla arranca limpia.
 */
class RecordarGrilla
{
    public const SESION = 'grillas';

    /** Atributo de la request: la grilla se dibujó con el estado recuperado (la vista corrige la URL). */
    public const RESTAURADA = 'grilla.restaurada';

    public function handle(Request $request, Closure $next): Response
    {
        $clave = $this->clave($request);

        if ($clave && $request->query() === [] && ! $request->ajax() && $this->vieneDeLaSeccion($request)
            && is_array($guardado = $request->session()->get($clave)) && $guardado !== []) {
            $request->query->replace($guardado);
            $request->attributes->set(self::RESTAURADA, true);
        }

        $respuesta = $next($request);

        if ($clave && $request->attributes->get(Grilla::ATRIBUTO) && $respuesta->isSuccessful()) {
            $request->session()->put($clave, $request->query());
        }

        return $respuesta;
    }

    private function clave(Request $request): ?string
    {
        $ruta = $request->route()?->getName();

        return $request->isMethod('GET') && $request->hasSession() && $ruta ? self::SESION.'.'.str_replace('.', '_', $ruta) : null;
    }

    private function vieneDeLaSeccion(Request $request): bool
    {
        $origen = parse_url((string) $request->headers->get('referer'));

        return ($origen['host'] ?? null) === $request->getHost()
            && str_starts_with($origen['path'] ?? '', rtrim($request->getPathInfo(), '/').'/');
    }
}
