<?php

namespace App\Http\Middleware;

use App\Support\Auditoria;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso en rutas: ->middleware('permiso:PERSONAS,CREAR'). El módulo es el código de modulos_sistema.
 *
 * Además registra las lecturas de los módulos sensibles (modulos_sistema.es_sensible): al abrir
 * el listado (acción VER) o el detalle/edición de un registro (GET con VER o EDITAR), en una carga
 * de página normal. No en las peticiones AJAX (búsqueda en vivo, paginación, verificación de
 * únicos, buscadores), ni en los formularios de alta, ni si la página no se pudo mostrar.
 *
 * Excepción explícita, marcada en la ruta con un tercer parámetro: "permiso:MODULO,VER,lectura-ajax".
 * Es para las peticiones AJAX que SÍ son una lectura de contenido (el detalle de una consulta en el
 * popup de la historia): registran VER aunque sean AJAX. La marca está en la ruta y no se deduce de
 * los encabezados, así los buscadores y la paginación siguen sin registrar.
 *
 * Otra marca: "tabla=consultas". Un listado registra su lectura en la tabla principal del módulo
 * (historias_clinicas para HISTORIA_CLINICA); con esta marca, en otra tabla del mismo módulo (la
 * pantalla Atención sin turno lista consultas). Tiene que ser una tabla auditada de ese módulo.
 * Las marcas se pueden combinar: "permiso:MODULO,VER,lectura-ajax,tabla=consultas".
 */
class VerificarPermiso
{
    /** Marca de ruta: esta petición AJAX es una lectura de contenido y se registra. */
    public const LECTURA_AJAX = 'lectura-ajax';

    /** Marca de ruta: la lectura de un listado se registra en esta tabla del módulo. */
    public const TABLA = 'tabla=';

    public function handle(Request $request, Closure $next, string $modulo, string $accion, string ...$marcas): Response
    {
        abort_unless(
            $request->user()?->tienePermiso($modulo, $accion),
            403,
            'No tiene permiso para acceder a esta sección.'
        );

        $respuesta = $next($request);

        if ($this->esLectura($request, $accion, $respuesta, in_array(self::LECTURA_AJAX, $marcas, true)) && Auditoria::esSensible($modulo)) {
            $registro = $this->registroDe($request);
            // Un módulo con varias tablas (historia clínica: historias y consultas) registra la lectura
            // en la tabla del registro de la URL; un listado, en la de la marca o la principal del módulo.
            Auditoria::registrarLectura($modulo, $registro?->getKey(), $registro?->getTable() ?? $this->tablaMarcada($modulo, $marcas));
        }

        return $respuesta;
    }

    /** La tabla de la marca "tabla=...", o null. Una tabla que no es del módulo es un error de la ruta. */
    private function tablaMarcada(string $modulo, array $marcas): ?string
    {
        foreach ($marcas as $marca) {
            if (str_starts_with($marca, self::TABLA)) {
                $tabla = substr($marca, strlen(self::TABLA));
                if ((Auditoria::modulosPorTabla()[$tabla] ?? null) !== $modulo) {
                    throw new LogicException("La tabla {$tabla} no es del módulo {$modulo}.");
                }

                return $tabla;
            }
        }

        return null;
    }

    /** Solo si se devolvió contenido (2xx): un 403, un 404 o una redirección no registran. */
    private function esLectura(Request $request, string $accion, Response $respuesta, bool $lecturaAjax): bool
    {
        return $request->isMethod('GET')
            && in_array($accion, ['VER', 'EDITAR'], true)
            && ($lecturaAjax || (! $request->ajax() && ! $request->wantsJson()))
            && $respuesta->isSuccessful();
    }

    /** El registro de la URL ({persona}, {usuario}, {consulta}, ...), o null en un listado. */
    private function registroDe(Request $request): ?Model
    {
        $parametro = collect($request->route()?->parameters() ?? [])->first();

        return $parametro instanceof Model ? $parametro : null;
    }
}
