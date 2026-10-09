<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Consulta de la historia clínica: la atiende un profesional, a partir de un turno o sin turno
 * (urgencia). Ciclo de vida (estados del módulo HISTORIA_CLINICA):
 *
 * - EN_PREPARACION: creada al preparar al paciente (anamnesis y signos vitales, a veces otra persona).
 * - EN_CURSO: el profesional la atiende (iniciada_en). Se autoguarda.
 * - FINALIZADO: cerrada (finalizada_en); se corrige con "Guardar cambios", queda en el historial.
 * - ANULADO: descartada (turno cancelado o ausente, o una urgencia vacía deshecha). Se conserva en la
 *   base pero no aparece en ningún listado, historial ni conteo.
 *
 * Quién escribe qué lo decide ConsultaPolicy; cada acción del flujo es un servicio de App\Support\Atencion.
 * Los cambios de las secciones quedan en la auditoría como EDITAR de la consulta: un solo historial.
 */
class Consulta extends Model
{
    use Auditable, TieneEstado;

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
            'iniciada_en' => 'datetime',
            'preparada_en' => 'datetime',
            'finalizada_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Consulta $consulta) {
            $consulta->fecha_hora = now();
        });
    }

    /** Sus estados son los del módulo HISTORIA_CLINICA (ver ModuloSistemaSeeder). */
    public static function moduloEstado(): string
    {
        return 'HISTORIA_CLINICA';
    }

    public static function moduloAuditoria(): string
    {
        return 'HISTORIA_CLINICA';
    }

    /** Las que existen para el usuario: todas menos las ANULADAS (que solo se conservan en la base). */
    public function scopeVisibles(Builder $query): void
    {
        $query->where($this->qualifyColumn('estado_id'), '!=', Estado::idDe(Estado::ANULADO));
    }

    public function scopeFinalizadas(Builder $query): void
    {
        $query->where($this->qualifyColumn('estado_id'), Estado::idDe(Estado::FINALIZADO));
    }

    public function enPreparacion(): bool
    {
        return $this->tieneEstado(Estado::EN_PREPARACION);
    }

    public function enCurso(): bool
    {
        return $this->tieneEstado(Estado::EN_CURSO);
    }

    public function finalizada(): bool
    {
        return $this->tieneEstado(Estado::FINALIZADO);
    }

    public function anulada(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    /** Se puede seguir cargando con autoguardado: en preparación o en curso. */
    public function abierta(): bool
    {
        return $this->enPreparacion() || $this->enCurso();
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
            // "Reposo: 48 horas", "Control en 7 días (retirado)".
            'indicaciones' => fn (Collection $indicaciones) => $indicaciones->sortBy(['orden', 'id'])->loadMissing('tipoIndicacion')
                ->map(fn (Indicacion $indicacion) => $indicacion->descripcionAuditoria())->values()->all(),
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

    /** Indicaciones generales (activas y retiradas), sin orden fijo: ordenar al mostrar. */
    public function indicaciones(): HasMany
    {
        return $this->hasMany(Indicacion::class);
    }

    /** Recetas (borradores, emitidas y anuladas). */
    public function recetas(): HasMany
    {
        return $this->hasMany(Receta::class);
    }

    /** La fecha de la atención: cuando se inició (o, si todavía no se atendió, cuando se creó). */
    public function fechaAtencion(): ?\Carbon\CarbonInterface
    {
        return $this->iniciada_en ?? $this->fecha_hora;
    }

    /** "08/10/2026 14:30", en la hora local. */
    public function fechaHoraTexto(): string
    {
        return Fecha::mostrar($this->fechaAtencion(), conHora: true);
    }
}
