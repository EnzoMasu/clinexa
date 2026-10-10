<?php

namespace App\Http\Middleware;

use App\Exceptions\ConsultaCerrada;
use App\Models\Consulta;
use App\Models\Receta;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso en rutas: ->middleware('consulta.abierta'). En las rutas de ESCRITURA sobre una consulta (o una de sus
 * recetas): si la consulta está cerrada (FINALIZADA o ANULADA), responde 409 "La consulta está cerrada y no
 * puede modificarse.", sin importar quién lo intente (profesional dueño, otro profesional, Administrador o
 * enfermería) y antes de la regla fina de cada acción (policies). Corre después del permiso de la ruta (sin
 * permiso es 403 y no se revela nada del registro) y de buscar el registro de la URL.
 *
 * Es la puerta de entrada; la regla de fondo está en los modelos (ProtegidoPorCierre). Anular una receta
 * EMITIDA no lleva este middleware: es lo único que se permite con la consulta cerrada.
 */
class ConsultaAbierta
{
    public function handle(Request $request, Closure $next): Response
    {
        $consulta = $request->route('consulta');
        if (! $consulta instanceof Consulta) {
            $receta = $request->route('receta');
            $consulta = $receta instanceof Receta ? Consulta::query()->select(['id', 'estado_id'])->find($receta->consulta_id) : null;
        }

        if ($consulta && in_array((int) $consulta->estado_id, Consulta::estadosCerrados(), true)) {
            throw new ConsultaCerrada;
        }

        return $next($request);
    }
}
