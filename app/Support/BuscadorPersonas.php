<?php

namespace App\Support;

use App\Models\Estado;
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
     * @param  bool  $conEmail  mostrar también el email (alta de Usuarios, donde el email del usuario es el de la persona)
     */
    public static function responder(Builder $disponibles, string $busqueda, bool $conEmail = false): JsonResponse
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
            'texto' => self::texto($persona, $conEmail),
        ]));
    }

    /**
     * Búsqueda entre quienes YA tienen un rol (al revés que personas-disponibles): registros ACTIVOS
     * del rol con persona ACTIVA, por nombre, documento o las columnas propias del rol (p. ej. el
     * número de ficha). Devuelve el id del rol (paciente_id, profesional_id), no el de la persona.
     *
     * @param  Builder  $roles  consulta del modelo del rol (Paciente::query(), Profesional::query())
     * @param  list<string>  $columnasRol  columnas del rol que también se buscan
     * @param  \Closure|null  $detalle  dato extra para el texto (p. ej. fn ($p) => "Ficha {$p->nro_ficha}")
     */
    public static function responderRol(Builder $roles, string $busqueda, array $columnasRol = [], ?\Closure $detalle = null): JsonResponse
    {
        $busqueda = trim($busqueda);
        if (mb_strlen($busqueda) < self::MINIMO) {
            return response()->json([]);
        }

        $tabla = $roles->getModel()->getTable();

        $registros = $roles
            ->activos()
            ->select("{$tabla}.*")
            ->join('personas', 'personas.id', '=', "{$tabla}.persona_id")
            ->where('personas.estado_id', Estado::idDe(Estado::ACTIVO))
            ->with('persona.tipoDocumento')
            ->where(function ($query) use ($busqueda, $tabla, $columnasRol) {
                $query->whereLike('personas.nro_documento', "{$busqueda}%")
                    ->orWhereLike('personas.apellidos', "%{$busqueda}%")
                    ->orWhereLike('personas.nombres', "%{$busqueda}%")
                    ->orWhereLike('personas.razon_social', "%{$busqueda}%");
                foreach ($columnasRol as $columna) {
                    $query->orWhereLike("{$tabla}.{$columna}", "%{$busqueda}%");
                }
            })
            ->orderByRaw('COALESCE(personas.apellidos, personas.razon_social)')
            ->orderBy('personas.nombres')
            ->limit(self::LIMITE)
            ->get();

        return response()->json($registros->map(fn ($registro) => [
            'id' => $registro->id,
            'texto' => self::textoRol($registro, $detalle),
        ]));
    }

    /** "Ruiz, Liz — CI 4567890 · Ficha FP-0000001". */
    public static function textoRol(object $registro, ?\Closure $detalle = null): string
    {
        return self::texto($registro->persona).($detalle ? ' · '.$detalle($registro) : '');
    }

    /**
     * Cómo se muestra una persona en el selector: "Ruiz, Liz — CI 4567890", y con $conEmail
     * "Ruiz, Liz — CI 4567890 (liz@clinexa.test)" (sin paréntesis si no tiene email).
     */
    public static function texto(Persona $persona, bool $conEmail = false): string
    {
        $texto = "{$persona->nombre_completo} — {$persona->tipoDocumento->codigo} {$persona->nro_documento}";

        return $conEmail && filled($persona->email) ? "{$texto} ({$persona->email})" : $texto;
    }
}
