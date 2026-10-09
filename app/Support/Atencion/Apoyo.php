<?php

namespace App\Support\Atencion;

use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\HistoriaClinica;
use App\Models\Turno;
use Illuminate\Database\QueryException;

/**
 * Lo que comparten los servicios del flujo de atención (Preparar, Atender, ...). Cada servicio es una
 * clase con un método ejecutar(): corre en su transacción, bloquea las filas que toca (lockForUpdate) y
 * revalida con ConsultaPolicy ADENTRO, con el estado de ese momento. Las reglas viven en los servicios y
 * en la policy, no en los controladores.
 */
final class Apoyo
{
    /** SQLSTATE de clave única repetida (PostgreSQL 23505; SQLite informa 23000). */
    private const CLAVE_REPETIDA = ['23505', '23000'];

    /** El turno bloqueado, con lo que hace falta para revalidar. */
    public static function turnoBloqueado(Turno $turno): Turno
    {
        return Turno::with(['paciente.historiaClinica', 'profesional'])->whereKey($turno->id)->lockForUpdate()->firstOrFail();
    }

    public static function consultaBloqueada(Consulta $consulta): Consulta
    {
        return Consulta::whereKey($consulta->id)->lockForUpdate()->firstOrFail();
    }

    public static function crearConsulta(HistoriaClinica $historia, ?Turno $turno, string $estado, ?int $profesionalId = null): Consulta
    {
        $consulta = new Consulta([
            'historia_clinica_id' => $historia->id,
            'turno_id' => $turno?->id,
            'profesional_id' => $turno?->profesional_id ?? $profesionalId,
        ]);
        $consulta->forceFill(['estado_id' => Estado::idDe($estado), 'iniciada_en' => $estado === Estado::EN_CURSO ? now() : null])->save();

        return $consulta;
    }

    /** La consulta que ya tiene el turno, si se puede seguir con ella; una anulada o cerrada, no. */
    public static function consultaDelTurno(Consulta $consulta): Consulta
    {
        if (! $consulta->abierta()) {
            throw new AccionRechazada('Este turno ya tiene una consulta ('.mb_strtolower($consulta->estado->nombre).').');
        }

        return $consulta;
    }

    /** Dos envíos simultáneos chocan con el unique de turno_id: el segundo usa la consulta que creó el primero. */
    public static function siYaExiste(QueryException $e, Turno $turno): Consulta
    {
        if (! in_array((string) $e->getCode(), self::CLAVE_REPETIDA, true)) {
            throw $e;
        }

        return self::consultaDelTurno(Consulta::where('turno_id', $turno->id)->firstOrFail());
    }

    /** Motivo, hallazgos, diagnósticos o indicaciones activos, o recetas sin anular. */
    public static function tieneContenidoClinico(Consulta $consulta): bool
    {
        return filled($consulta->motivo_consulta)
            || filled($consulta->examenFisico()->value('hallazgos'))
            || $consulta->diagnosticos()->where('activo', true)->exists()
            || $consulta->indicaciones()->where('activo', true)->exists()
            || $consulta->recetas()->where('estado_id', '!=', Estado::idDe(Estado::ANULADO))->exists();
    }

    /** Bloques de anamnesis activos o algún signo vital cargado. */
    public static function tienePreparacion(Consulta $consulta): bool
    {
        $examen = $consulta->examenFisico()->first();

        return $consulta->bloquesAnamnesis()->where('activo', true)->exists()
            || ($examen && collect(FormularioConsulta::SIGNOS_VITALES)->contains(fn (string $campo) => $examen->{$campo} !== null));
    }
}
