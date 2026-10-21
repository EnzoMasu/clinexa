<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El reparto de una prestación (docs/reparto-economico.md), uno por prestación:
 *
 * - PENDIENTE ("pendiente de reparto"): sin líneas, con el motivo (falta un acuerdo, o los porcentajes superan 100).
 * - REPARTIDO: con sus líneas (LineaReparto) fijadas; no cambian aunque cambien los acuerdos.
 * - ANULADO: al anular la prestación, con su motivo; las líneas se conservan.
 *
 * El modelo solo admite PENDIENTE -> PENDIENTE (otro motivo), PENDIENTE -> REPARTIDO y -> ANULADO; rechaza el resto y el borrado.
 */
class RepartoPrestacion extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'repartos_prestacion';

    public const ETIQUETAS = [Estado::PENDIENTE => 'Pendiente de reparto', Estado::REPARTIDO => 'Repartido', Estado::ANULADO => 'Anulado'];

    private const CAMPOS_DE_MOTIVO = ['motivo_pendiente', 'updated_at'];

    private const CAMPOS_DE_REPARTO = ['estado_id', 'motivo_pendiente', 'monto_base', 'moneda_id', 'equipo_id', 'repartido_en', 'repartido_por', 'updated_at'];

    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulado_en', 'motivo_anulacion', 'anulado_por', 'updated_at'];

    protected $fillable = ['prestacion_id', 'monto_base', 'moneda_id'];

    public static function moduloEstado(): string
    {
        return 'REPARTOS';
    }

    protected function casts(): array
    {
        return [
            'repartido_en' => 'datetime',
            'anulado_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (RepartoPrestacion $reparto) {
            $desde = Estado::find($reparto->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($reparto->estado_id)?->codigo;
            $permitidos = match (true) {
                $desde === Estado::PENDIENTE && $hacia === Estado::PENDIENTE => self::CAMPOS_DE_MOTIVO,
                $desde === Estado::PENDIENTE && $hacia === Estado::REPARTIDO => self::CAMPOS_DE_REPARTO,
                in_array($desde, [Estado::PENDIENTE, Estado::REPARTIDO], true) && $hacia === Estado::ANULADO => self::CAMPOS_DE_ANULACION,
                default => null,
            };
            if ($permitidos === null || array_diff(array_keys($reparto->getDirty()), $permitidos) !== []) {
                throw RegistroEconomicoInmutable::reparto();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return $accion === AccionAuditoria::ANULAR && $this->motivo_anulacion ? "Motivo: {$this->motivo_anulacion}" : null;
    }

    public function prestacion(): BelongsTo
    {
        return $this->belongsTo(Prestacion::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    public function repartidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repartido_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    /** Las líneas, en el orden en que se muestran: clínica, profesional, equipo. */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaReparto::class)->orderBy('id');
    }

    public function pendiente(): bool
    {
        return $this->tieneEstado(Estado::PENDIENTE);
    }

    public function repartido(): bool
    {
        return $this->tieneEstado(Estado::REPARTIDO);
    }

    public function anulado(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }
}
