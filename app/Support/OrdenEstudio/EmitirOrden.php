<?php

namespace App\Support\OrdenEstudio;

use App\Exceptions\AccionRechazada;
use App\Models\Constancia;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Estudio;
use App\Models\OrdenEstudio;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emite una orden de estudio de una consulta EN_CURSO o FINALIZADA, con la consulta bloqueada. Asigna número correlativo
 * (OE-0000001), fecha de hoy en hora de Paraguay, snapshot y huella sha256. La autorización (la policy) la hace el llamador;
 * acá se revalida lo que depende de la base: el estado de la consulta, que los estudios elegidos del catálogo estén activos,
 * que haya al menos un estudio y que los diagnósticos elegidos sean vigentes y de esta consulta.
 *
 * Los estudios escritos a mano van solo en esta orden: no se agregan al catálogo.
 */
final class EmitirOrden
{
    public const MAXIMO_ESTUDIOS = 30;

    public const MAXIMO_INDICACION = 2000;

    /** SQLSTATE de clave única repetida (PostgreSQL 23505; SQLite informa 23000). */
    private const CLAVE_REPETIDA = ['23505', '23000'];

    private const INTENTOS_NUMERO = 5;

    /**
     * @param  array{estudios: list<int>, otros: list<string>, indicacion_clinica: ?string, diagnosticos: list<int>}  $datos
     */
    public static function ejecutar(User $usuario, Consulta $consulta, array $datos): OrdenEstudio
    {
        return DB::transaction(function () use ($usuario, $consulta, $datos) {
            $consulta = Consulta::with('estado')->whereKey($consulta->id)->lockForUpdate()->firstOrFail();
            if (! $consulta->enCurso() && ! $consulta->finalizada()) {
                throw new AccionRechazada('La orden de estudio se emite solo de una consulta en curso o finalizada.');
            }

            $estudios = self::estudios($datos);

            $elegidos = array_values(array_unique(array_map('intval', $datos['diagnosticos'] ?? [])));
            $diagnosticos = $consulta->diagnosticos()->with('cie10')->where('activo', true)->whereIn('id', $elegidos)->get();
            if ($diagnosticos->count() !== count($elegidos)) {
                throw ValidationException::withMessages(['diagnosticos' => 'Elija solo diagnósticos vigentes de esta consulta.']);
            }

            $snapshot = DatosOrden::armar($consulta, $usuario, $estudios, $datos['indicacion_clinica'] ?? null, $diagnosticos);

            return self::guardar($consulta, $usuario, $snapshot);
        });
    }

    /**
     * Los estudios de la orden: primero los del catálogo (activos, por nombre), después los escritos a mano (en el orden en
     * que se escribieron, sin repetidos ni vacíos).
     *
     * @return list<array{nombre: string, estudio_id: ?int}>
     */
    private static function estudios(array $datos): array
    {
        $ids = array_values(array_unique(array_map('intval', $datos['estudios'] ?? [])));
        $delCatalogo = Estudio::activos()->whereIn('id', $ids)->orderBy('nombre')->get();
        if ($delCatalogo->count() !== count($ids)) {
            throw ValidationException::withMessages(['estudios' => 'Elija solo estudios activos del catálogo.']);
        }

        $otros = collect($datos['otros'] ?? [])
            ->map(fn ($texto) => trim(preg_replace('/\s+/u', ' ', (string) $texto)))
            ->filter(fn (string $texto) => $texto !== '')
            ->unique(fn (string $texto) => mb_strtolower($texto))
            ->values();

        $estudios = [
            ...$delCatalogo->map(fn (Estudio $estudio) => ['nombre' => $estudio->nombre, 'estudio_id' => $estudio->id])->all(),
            ...$otros->map(fn (string $texto) => ['nombre' => $texto, 'estudio_id' => null])->all(),
        ];

        if ($estudios === []) {
            throw ValidationException::withMessages(['estudios' => 'Indique al menos un estudio.']);
        }
        if (count($estudios) > self::MAXIMO_ESTUDIOS) {
            throw ValidationException::withMessages(['estudios' => 'Una orden puede llevar hasta '.self::MAXIMO_ESTUDIOS.' estudios.']);
        }

        return $estudios;
    }

    /** Si otra emisión simultánea tomó el mismo número, el unique de la base lo rechaza y se reintenta con el siguiente. */
    private static function guardar(Consulta $consulta, User $usuario, array $snapshot): OrdenEstudio
    {
        for ($intento = 1; ; $intento++) {
            $numero = OrdenEstudio::siguienteNumero();
            $datos = [...$snapshot, 'numero' => $numero];
            try {
                return DB::transaction(fn () => OrdenEstudio::create([
                    'numero' => $numero, 'consulta_id' => $consulta->id, 'fecha' => $datos['fecha'],
                    'emitida_en' => now(), 'snapshot' => $datos, 'huella' => Constancia::calcularHuella($datos),
                    'usuario_id' => $usuario->id, 'estado_id' => Estado::idDe(Estado::EMITIDO),
                ]));
            } catch (QueryException $e) {
                if ($intento >= self::INTENTOS_NUMERO || ! in_array((string) $e->getCode(), self::CLAVE_REPETIDA, true)) {
                    throw $e;
                }
            }
        }
    }
}
