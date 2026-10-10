<?php

namespace App\Models;

use App\Exceptions\AccionRechazada;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Support\Fecha;
use Illuminate\Support\Facades\DB;

/**
 * Turno de un paciente con un profesional. No se edita ni se borra: solo cambia de estado.
 * Las transiciones válidas están en TRANSICIONES (y se aplican con pasarA). Solo CANCELADO libera el
 * horario: SALTADO, EN_CONSULTA, ATENDIDO y AUSENTE lo siguen ocupando. La base impide que se superponga con otro (no CANCELADO) del mismo profesional o
 * del mismo consultorio.
 */
class Turno extends Model
{
    use Auditable, TieneEstado;

    /**
     * Estado actual => estados a los que puede pasar. Es el ÚNICO lugar donde se definen; todo cambio de
     * estado pasa por pasarA(). ATENDIDO, CANCELADO y AUSENTE son finales.
     *
     * - SALTADO: "No se presentó" (lo llamaron y no estaba); se lo vuelve a llamar.
     * - EN_CONSULTA: "Atender". De ahí solo se sale a ATENDIDO (Finalizar la consulta) o de vuelta a
     *   PENDIENTE (Deshacer atención): un turno en consulta no se cancela ni se marca ausente.
     */
    public const TRANSICIONES = [
        Estado::PENDIENTE => [Estado::CONFIRMADO, Estado::SALTADO, Estado::EN_CONSULTA, Estado::AUSENTE, Estado::CANCELADO],
        Estado::CONFIRMADO => [Estado::SALTADO, Estado::EN_CONSULTA, Estado::AUSENTE, Estado::CANCELADO],
        Estado::SALTADO => [Estado::EN_CONSULTA, Estado::AUSENTE, Estado::CANCELADO],
        Estado::EN_CONSULTA => [Estado::ATENDIDO, Estado::PENDIENTE],
    ];

    /**
     * Botones manuales del listado de Turnos (EDITAR sobre TURNOS): acción => [estado al que lleva, texto].
     * Nunca llevan a SALTADO, EN_CONSULTA ni ATENDIDO: eso lo hacen la pantalla Consulta y sus servicios.
     */
    /** Estados de ausencia: solo desde la hora del turno (pasarA). */
    public const SOLO_DESDE_SU_HORA = [Estado::AUSENTE, Estado::SALTADO];

    public const AUN_NO_ES_SU_HORA = 'Todavía no llegó la hora del turno (%s). Solo puede marcar la ausencia desde esa hora.';

    public const ACCIONES = [
        'confirmar' => [Estado::CONFIRMADO, 'Confirmar'],
        'ausente' => [Estado::AUSENTE, 'Ausente'],
        'cancelar' => [Estado::CANCELADO, 'Cancelar'],
    ];

    protected $table = 'turnos';

    // rango es una columna generada por la base: nunca se escribe.
    protected $fillable = [
        'paciente_id',
        'profesional_id',
        'consultorio_id',
        'procedimiento_id',
        'equipo_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'estado_id',
        'origen_turno_id',
        'observaciones',
    ];

    protected $hidden = ['rango'];

    public static function moduloEstado(): string
    {
        return 'TURNOS';
    }

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    public function consultorio(): BelongsTo
    {
        return $this->belongsTo(Consultorio::class);
    }

    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class);
    }

    /** La consulta que generó al atenderse (como mucho una). */
    public function consulta(): HasOne
    {
        return $this->hasOne(Consulta::class);
    }

    public function origenTurno(): BelongsTo
    {
        return $this->belongsTo(OrigenTurno::class);
    }

    /** Los que ocupan horario: todos menos los CANCELADO. */
    public function scopeOcupanHorario(Builder $query): void
    {
        $query->where($this->qualifyColumn('estado_id'), '!=', Estado::idDe(Estado::CANCELADO));
    }

    /** Botones manuales que se pueden aplicar en su estado actual (vacío si el estado es final). */
    public function accionesPosibles(): array
    {
        return array_keys(array_filter(self::ACCIONES, fn (array $accion) => $this->puedePasarA($accion[0])));
    }

    public function puede(string $accion): bool
    {
        return in_array($accion, $this->accionesPosibles(), true);
    }

    public function puedePasarA(string $estado): bool
    {
        return in_array($estado, self::TRANSICIONES[$this->estado?->codigo] ?? [], true);
    }

    /**
     * Cambia el estado, si la transición es válida (si no, DomainException con el motivo). Al pasar a
     * CANCELADO o AUSENTE, su consulta EN_PREPARACION (si la hay) pasa a ANULADO en la misma transacción:
     * los datos se conservan, pero no aparece en listados, historial ni conteos.
     */
    /** La hora del turno ya llegó (en punto, inclusive; hora de Paraguay). Ver Fecha::yaLlego. */
    public function llegoSuHora(): bool
    {
        return Fecha::yaLlego($this->fecha->format('Y-m-d'), $this->hora_inicio);
    }

    /** Aviso para quien intenta marcar la ausencia antes de la hora del turno. */
    public function avisoAunNoEsSuHora(): string
    {
        return sprintf(self::AUN_NO_ES_SU_HORA, substr($this->hora_inicio, 0, 5));
    }

    public function pasarA(string $estado): void
    {
        if (! $this->puedePasarA($estado)) {
            throw new DomainException(sprintf('Un turno %s no puede pasar a %s.', mb_strtolower((string) $this->estado?->nombre), mb_strtolower(Estado::where('codigo', $estado)->value('nombre') ?? $estado)));
        }
        // La ausencia (AUSENTE, o SALTADO por "No se presentó") solo desde la hora del turno: el único
        // lugar que lo decide, para todos los caminos (botón manual, Consulta, Cerrar jornada).
        if (in_array($estado, self::SOLO_DESDE_SU_HORA, true) && ! $this->llegoSuHora()) {
            throw new AccionRechazada($this->avisoAunNoEsSuHora());
        }
        DB::transaction(function () use ($estado) {
            $this->update(['estado_id' => Estado::idDe($estado)]);
            $this->unsetRelation('estado');

            if (in_array($estado, [Estado::CANCELADO, Estado::AUSENTE], true)) {
                $consulta = Consulta::where('turno_id', $this->id)->lockForUpdate()->first();
                if ($consulta?->enPreparacion()) {
                    $consulta->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO)])->save();
                }
            }
        });
    }
}
