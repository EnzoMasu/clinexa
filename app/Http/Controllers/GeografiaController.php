<?php

namespace App\Http\Controllers;

use App\Models\Ciudad;
use App\Models\Departamento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Opciones del selector País → Departamento → Ciudad (componente x-geografia.selector-ciudad),
 * pedidas por fetch al elegir el nivel anterior. Solo devuelve registros ACTIVOS.
 */
class GeografiaController extends Controller
{
    public function departamentos(Request $request): JsonResponse
    {
        $request->validate(['pais_id' => ['required', 'integer']]);

        return response()->json(
            Departamento::without('estado')->activos()->where('pais_id', $request->integer('pais_id'))
                ->orderBy('nombre')->get(['id', 'nombre'])
        );
    }

    public function ciudades(Request $request): JsonResponse
    {
        $request->validate(['departamento_id' => ['required', 'integer']]);

        return response()->json(
            Ciudad::without('estado')->activos()->where('departamento_id', $request->integer('departamento_id'))
                ->orderBy('nombre')->get(['id', 'nombre'])
        );
    }
}
