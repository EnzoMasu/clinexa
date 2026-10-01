<?php

namespace App\Http\Controllers;

use App\Support\Unicidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoint compartido de verificación "al vuelo" de campos únicos: responde si un valor ya
 * existe. Lo usa resources/js/verificar-unico.js al salir del campo.
 */
class VerificarUnicoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'campo' => ['required', 'string', Rule::in(array_keys(Unicidad::CAMPOS))],
            'valor' => ['nullable', 'string', 'max:200'],
            'ignorar' => ['nullable', 'string', 'max:50'],
        ]);

        $editando = filled($datos['ignorar'] ?? null);
        abort_unless(Unicidad::puedeConsultar($request->user(), $datos['campo'], $editando), 403);

        $con = collect(Unicidad::CAMPOS[$datos['campo']]['con'] ?? [])
            ->mapWithKeys(fn (string $columna) => [$columna => $request->query($columna)])
            ->all();

        $mensaje = Unicidad::conflicto($datos['campo'], (string) ($datos['valor'] ?? ''), $datos['ignorar'] ?? null, $con);

        return response()->json(['disponible' => $mensaje === null, 'mensaje' => $mensaje]);
    }
}
