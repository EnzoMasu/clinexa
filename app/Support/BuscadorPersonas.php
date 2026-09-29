<?php

namespace App\Support;

use App\Models\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Búsqueda de personas del componente x-admin.selector-persona (alta de Usuarios y de los roles
 * de negocio). Siempre filtra en el servidor y devuelve como máximo LIMITE resultados; con menos
 * de MINIMO caracteres responde vacío sin consultar la base (el JS tampoco pregunta).
 */
class BuscadorPersonas
{
    /** Caracteres mínimos para buscar. */
    public const MINIMO = 2;

    /** Tope de personas por respuesta, haya las que haya en la base. */
    public const LIMITE = 15;

    /**
     * @param  Builder  $disponibles  personas que pueden elegirse (ya filtradas por estado, tipo, rol, etc.)
     */
    public static function responder(Builder $disponibles, string $busqueda): JsonResponse
    {
        $busqueda = trim($busqueda);
        if (mb_strlen($busqueda) < self::MINIMO) {
            return response()->json([]);
        }

        $personas = $disponibles
            ->with('tipoDocumento')
            ->where(fn ($query) => $query
                ->whereLike('personas.nro_documento', "{$busqueda}%")
                ->orWhereLike('personas.apellidos', "%{$busqueda}%")
                ->orWhereLike('personas.nombres', "%{$busqueda}%")
                ->orWhereLike('personas.razon_social', "%{$busqueda}%"))
            ->orderByRaw('COALESCE(personas.apellidos, personas.razon_social)')
            ->orderBy('personas.nombres')
            ->limit(self::LIMITE)
            ->get();

        return response()->json($personas->map(fn (Persona $persona) => [
            'id' => $persona->id,
            'texto' => self::texto($persona),
        ]));
    }

    /** Cómo se muestra una persona en el selector: "Ruiz, Liz — CI 4567890". */
    public static function texto(Persona $persona): string
    {
        return "{$persona->nombre_completo} — {$persona->tipoDocumento->codigo} {$persona->nro_documento}";
    }
}
