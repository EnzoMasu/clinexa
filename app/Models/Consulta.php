<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Consulta de la historia clínica: la atiende un profesional, a partir de un turno CONFIRMADO o sin
 * turno (urgencia). Se edita sin límite de tiempo, solo por el profesional que la atiende
 * (ConsultaPolicy). Los cambios de anamnesis, examen físico y diagnósticos quedan en la auditoría
 * como EDITAR de la consulta, así tiene un solo historial.
 */
class Consulta extends Model
{
    use Auditable;

    protected $table = 'consultas';

    /** Con microsegundos: updated_at es el control de concurrencia del formulario. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    // fecha_hora no es asignable: se fija al guardar por primera vez (booted) y no cambia.
    protected $fillable = [
        'historia_clinica_id',
        'turno_id',
        'profesional_id',
        'motivo_consulta',
    ];

    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Consulta $consulta) {
            $consulta->fecha_hora = now();
        });
    }

    public static function moduloAuditoria(): string
    {
        return 'HISTORIA_CLINICA';
    }

    /** updated_at como se manda en el campo oculto del formulario (control de concurrencia). */
    public function version(): string
    {
        return $this->updated_at?->format('Y-m-d H:i:s.u') ?? '';
    }

    /**
     * En el log, cada lista legible: "Alergias: penicilina", "J06.9 — Rinofaringitis aguda
     * (CONFIRMADO, principal)", "Presión arterial: 120/80".
     */
    public function relacionesAuditadas(): array
    {
        return [
            'bloquesAnamnesis' => fn (Collection $bloques) => $bloques->sortBy(['orden', 'id'])->loadMissing('tipoBloqueAnamnesis')
                ->map(fn (BloqueAnamnesis $bloque) => $bloque->descripcion())->values()->all(),
            'examenFisico' => fn (Collection $examenes) => $examenes->first()?->descripcion() ?? [],
            'diagnosticos' => fn (Collection $diagnosticos) => $diagnosticos->sortBy('id')->loadMissing('cie10')
                ->map(fn (Diagnostico $diagnostico) => $diagnostico->descripcion())->values()->all(),
        ];
    }

    public function historiaClinica(): BelongsTo
    {
        return $this->belongsTo(HistoriaClinica::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    /** Todos los bloques (activos y retirados), sin orden fijo: ordenar al mostrar. */
    public function bloquesAnamnesis(): HasMany
    {
        return $this->hasMany(BloqueAnamnesis::class);
    }

    public function examenFisico(): HasOne
    {
        return $this->hasOne(ExamenFisico::class);
    }

    /** Todos los diagnósticos (activos y retirados). */
    public function diagnosticos(): HasMany
    {
        return $this->hasMany(Diagnostico::class);
    }

    /** "08/10/2026 14:30", en la hora local. */
    public function fechaHoraTexto(): string
    {
        return Fecha::mostrar($this->fecha_hora, conHora: true);
    }
}
