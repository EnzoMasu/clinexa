<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarifa de un procedimiento (docs/tarifas-prestaciones.md), del profesional o general (profesional vacío). Versionada:
 *
 * - VIGENTE: la abierta (sin "hasta"); una sola por procedimiento y profesional (índice único tarifas_una_vigente).
 * - HISTORICO: cerrada al cambiar el monto (Tarifas\CambiarTarifa), con su "hasta" = el día anterior a la nueva.
 *
 * Se aplica a un día por su rango [desde, hasta], no por el estado: una cerrada sigue valiendo para los días de su rango
 * (y la nueva puede empezar más adelante). El modelo rechaza cualquier otro cambio, y el borrado.
 */
class Tarifa extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'tarifas';

    /** Lo único que cambia al cerrarla. */
    private const CAMPOS_DE_CIERRE = ['estado_id', 'vigente_hasta', 'updated_at'];

    public const MONTO_MAXIMO = 999_999_999_999;

    protected $fillable = ['procedimiento_id', 'profesional_id', 'monto', 'moneda_id', 'vigente_desde', 'reemplaza_a_id', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'TARIFAS';
    }

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tarifa $tarifa) {
            $tarifa->estado_id = Estado::idDe(Estado::VIGENTE);
        });

        static::updating(function (Tarifa $tarifa) {
            $cierra = Estado::find($tarifa->getOriginal('estado_id'))?->codigo === Estado::VIGENTE
                && Estado::find($tarifa->estado_id)?->codigo === Estado::HISTORICO
                && $tarifa->vigente_hasta !== null;
            if (! $cierra || array_diff(array_keys($tarifa->getDirty()), self::CAMPOS_DE_CIERRE) !== []) {
                throw RegistroEconomicoInmutable::tarifa();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class);
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reemplazaA(): BelongsTo
    {
        return $this->belongsTo(Tarifa::class, 'reemplaza_a_id');
    }

    public function vigente(): bool
    {
        return $this->tieneEstado(Estado::VIGENTE);
    }

    public function esGeneral(): bool
    {
        return $this->profesional_id === null;
    }

    /** Las del mismo procedimiento y profesional (la general: sin profesional). */
    public function scopeDeLaClave(Builder $query, int $procedimientoId, ?int $profesionalId): void
    {
        $query->where('procedimiento_id', $procedimientoId)
            ->when($profesionalId, fn ($query) => $query->where('profesional_id', $profesionalId), fn ($query) => $query->whereNull('profesional_id'));
    }

    /** Las que se aplican a ese día (aaaa-mm-dd), por su rango. */
    public function scopeAplicaA(Builder $query, string $dia): void
    {
        $query->whereDate('vigente_desde', '<=', $dia)
            ->where(fn ($query) => $query->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $dia));
    }

    /** No tuvo ningún día de vigencia: se reemplazó el mismo día en que empezaba. */
    public function sinVigencia(): bool
    {
        return $this->vigente_hasta !== null && $this->vigente_hasta->lt($this->vigente_desde);
    }

    public function montoTexto(): string
    {
        return $this->moneda->formatear($this->monto);
    }

    /** "desde el 20/10/2026", "del 01/10/2026 al 19/10/2026" o "sin vigencia (reemplazada el mismo día)". */
    public function vigenciaTexto(): string
    {
        return match (true) {
            $this->sinVigencia() => 'Sin vigencia (reemplazada el mismo día)',
            $this->vigente_hasta === null => 'Desde el '.Fecha::mostrar($this->vigente_desde),
            default => 'Del '.Fecha::mostrar($this->vigente_desde).' al '.Fecha::mostrar($this->vigente_hasta),
        };
    }

    public function profesionalTexto(): string
    {
        return $this->profesional?->persona?->nombre_completo ?? 'General (todos los profesionales)';
    }
}
