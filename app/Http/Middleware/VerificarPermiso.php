<?php

namespace App\Http\Middleware;

use App\Support\Auditoria;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso en rutas: ->middleware('permiso:PERSONAS,CREAR'). El módulo es el código de modulos_sistema.
 *
 * Además registra las lecturas de los módulos sensibles (modulos_sistema.es_sensible): al abrir
 * el listado (acción VER) o el detalle/edición de un registro (GET con VER o EDITAR), en una carga
 * de página normal. No en las peticiones AJAX (búsqueda en vivo, paginación, verificación de
 * únicos, buscadores), ni en los formularios de alta, ni si la página no se pudo mostrar.
 */
class VerificarPermiso
{
    public function handle(Request $request, Closure $next, string $modulo, string $accion): Response
    {
        abort_unless(
            $request->user()?->tienePermiso($modulo, $accion),
            403,
            'No tiene permiso para acceder a esta sección.'
        );

        $respuesta = $next($request);

        if ($this->esLectura($request, $accion, $respuesta) && Auditoria::esSensible($modulo)) {
            $registro = $this->registroDe($request);
            // Un módulo con varias tablas (historia clínica: historias y consultas) registra la lectura
            // en la tabla del registro de la URL; un listado, en la tabla principal del módulo.
            Auditoria::registrarLectura($modulo, $registro?->getKey(), $registro?->getTable());
        }

        return $respuesta;
    }

    private function esLectura(Request $request, string $accion, Response $respuesta): bool
    {
        return $request->isMethod('GET')
            && in_array($accion, ['VER', 'EDITAR'], true)
            && ! $request->ajax() && ! $request->wantsJson()
            && $respuesta->isSuccessful();
    }

    /** El registro de la URL ({persona}, {usuario}, {consulta}, ...), o null en un listado. */
    private function registroDe(Request $request): ?Model
    {
        $parametro = collect($request->route()?->parameters() ?? [])->first();

        return $parametro instanceof Model ? $parametro : null;
    }
}
