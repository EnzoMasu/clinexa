<?php

namespace App\Support\Atencion;

use App\Enums\TipoTurno;
use App\Models\Estado;
use App\Models\Profesional;
use App\Models\Turno;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lo que espera al profesional en sus botones "Entre pacientes (N)" y "Urgencias (N)" (pantalla Consulta), solo
 * de HOY y solo sus turnos:
 *
 * - Entre pacientes: turnos ENTRE_PACIENTES en PENDIENTE, CONFIRMADO o SALTADO, por orden de carga.
 * - Urgencias: turnos no atendidos (PENDIENTE, CONFIRMADO o SALTADO) que son URGENCIA o cuya consulta de triaje
 *   (no anulada) está marcada urgente, una vez por turno; la más antigua primero (hora de registro de la urgencia
 *   o de la marca del triaje). No cuentan EN_CONSULTA, ATENDIDO, CANCELADO ni AUSENTE.
 *
 * Los conteos son dos consultas fijas (y una más para la última urgencia), sin contenido clínico.
 */
final class PendientesDelProfesional
{
    public const POR_ATENDER = [Estado::PENDIENTE, Estado::CONFIRMADO, Estado::SALTADO];

    /**
     * Conteos y la última urgencia (solo su id de turno y el nombre del paciente), para el refresco cada 15 s.
     *
     * @return array{entre_pacientes: int, urgencias: int, ultima: ?array{id: int, nombre: string}}
     */
    public static function contadores(Profesional $profesional): array
    {
        $urgencias = self::urgenciasQuery($profesional);
        $ultima = (clone $urgencias)->with('paciente.persona')
            ->leftJoin('consultas', fn ($join) => $join->on('consultas.turno_id', '=', 'turnos.id')->where('consultas.triaje_urgente', true))
            ->orderByRaw("CASE WHEN turnos.tipo = 'URGENCIA' THEN turnos.llegada_en ELSE consultas.triaje_urgente_en END DESC")
            ->orderByDesc('turnos.id')
            ->select('turnos.*')->first();

        return [
            'entre_pacientes' => self::entrePacientesQuery($profesional)->count(),
            'urgencias' => $urgencias->count(),
            'ultima' => $ultima ? ['id' => $ultima->id, 'nombre' => $ultima->paciente->persona->nombre_completo] : null,
        ];
    }

    /** Para el popup "Entre pacientes": paciente, estado y triaje (sin la observación), por orden de carga. */
    public static function entrePacientes(Profesional $profesional): Collection
    {
        return self::entrePacientesQuery($profesional)
            ->with(['estado', 'paciente.persona', 'consulta' => fn ($query) => self::columnasConsulta($query)])
            ->orderBy('turnos.created_at')->orderBy('turnos.id')
            ->get();
    }

    /** Para el popup "Urgencias": con la observación del triaje. La más antigua primero. */
    public static function urgencias(Profesional $profesional): Collection
    {
        return self::urgenciasQuery($profesional)
            ->with(['estado', 'paciente.persona', 'consulta' => fn ($query) => self::columnasConsulta($query, conObservacion: true)])
            ->get()
            ->sortBy(fn (Turno $turno) => [self::momento($turno)?->getTimestamp() ?? PHP_INT_MAX, $turno->id])
            ->values();
    }

    /** Cuándo pasó a ser urgencia: el registro (URGENCIA) o la marca del triaje. */
    public static function momento(Turno $turno): ?\Carbon\CarbonInterface
    {
        return $turno->tipo === TipoTurno::URGENCIA ? ($turno->llegada_en ?? $turno->created_at) : $turno->consulta?->triaje_urgente_en;
    }

    /** "Recepción" (registrada como urgencia) o "Triaje" (un turno que el triaje marcó urgente). */
    public static function origen(Turno $turno): string
    {
        return $turno->tipo === TipoTurno::URGENCIA ? 'Recepción' : 'Triaje';
    }

    /** El turno se muestra como urgencia (URGENCIA, o triaje urgente en su consulta): pide confirmación con una consulta en curso. */
    public static function esUrgencia(Turno $turno): bool
    {
        return $turno->tipo === TipoTurno::URGENCIA
            || ($turno->consulta && $turno->consulta->triaje_urgente && (int) $turno->consulta->estado_id !== Estado::idDe(Estado::ANULADO));
    }

    private static function deHoy(Profesional $profesional): Builder
    {
        return Turno::query()
            ->where('turnos.profesional_id', $profesional->id)
            ->whereDate('turnos.fecha', Fecha::hoy()->format('Y-m-d'))
            ->whereIn('turnos.estado_id', array_map(fn (string $codigo) => Estado::idDe($codigo), self::POR_ATENDER));
    }

    private static function entrePacientesQuery(Profesional $profesional): Builder
    {
        return self::deHoy($profesional)->delTipo(TipoTurno::ENTRE_PACIENTES);
    }

    private static function urgenciasQuery(Profesional $profesional): Builder
    {
        return self::deHoy($profesional)->where(fn (Builder $query) => $query
            ->where('turnos.tipo', TipoTurno::URGENCIA->value)
            ->orWhereHas('consulta', fn (Builder $consulta) => $consulta->where('triaje_urgente', true)
                ->where('estado_id', '!=', Estado::idDe(Estado::ANULADO))));
    }

    private static function columnasConsulta($query, bool $conObservacion = false): void
    {
        $query->select(['id', 'turno_id', 'estado_id', 'preparada_en', 'historia_clinica_id', 'profesional_id', 'triaje_urgente', 'triaje_urgente_en',
            ...($conObservacion ? ['triaje_observacion'] : [])])
            ->withExists(['examenFisico as con_alertas' => fn ($q) => $q->whereNotNull('signos_alertas')]);
    }
}
