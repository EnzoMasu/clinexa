<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Turno de un paciente con un profesional. No se edita ni se borra: solo cambia de estado.
 * PENDIENTE -> CONFIRMADO o CANCELADO; CONFIRMADO -> CANCELADO o AUSENTE, o ATENDIDO, que solo se
 * alcanza guardando la consulta del turno (ConsultaController); los demás son finales. La base impide que se superponga con otro (no CANCELADO) del mismo profesional o
 * del mismo consultorio.
 */
class Turno extends Model
{
    use Auditable, TieneEstado;

    /** Acción => [estado al que lleva, texto del botón]. "Atender" no está: abre el formulario de la consulta. */
    public const ACCIONES = [
        'confirmar' => [Estado::CONFIRMADO, 'Confirmar'],
        'ausente' => [Estado::AUSENTE, 'Ausente'],
        'cancelar' => [Estado::CANCELADO, 'Cancelar'],
    ];

    /** Estado actual => acciones posibles. */
    public const TRANSICIONES = [
        Estado::PENDIENTE => ['confirmar', 'cancelar'],
        Estado::CONFIRMADO => ['ausente', 'cancelar'],
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

    /** Acciones que se pueden aplicar en su estado actual (vacío si el estado es final). */
    public function accionesPosibles(): array
    {
        return self::TRANSICIONES[$this->estado->codigo] ?? [];
    }

    public function puede(string $accion): bool
    {
        return in_array($accion, $this->accionesPosibles(), true);
    }
}
