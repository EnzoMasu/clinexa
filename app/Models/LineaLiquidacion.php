<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de una liquidación (docs/liquidaciones-aseguradoras.md): una prestación, con el snapshot de la planilla (paciente,
 * documento, número de asegurado, fecha, procedimiento, número de visación y lo que paga la aseguradora). Sin nada clínico. Solo se
 * libera (una vez, con motivo): quitada de la liquidación en preparación o por la anulación de la liquidación; la prestación vuelve a
 * quedar libre. Una prestación, en una sola línea no liberada (`lineas_liquidacion_una_por_prestacion`). No se borra.
 */
class LineaLiquidacion extends Model
{
    use Auditable;

    protected $table = 'lineas_liquidacion';

    /** Quitada de la liquidación en preparación, o liberada al anular la liquidación. */
    public const QUITADA = 'QUITADA';

    public const ANULADA = 'ANULADA';

    private const CAMPOS_DE_LIBERACION = ['liberacion', 'liberada_en', 'liberada_por', 'motivo_liberacion', 'updated_at'];

    protected $fillable = ['liquidacion_id', 'prestacion_id', 'paciente', 'documento', 'numero_asegurado', 'fecha_servicio', 'procedimiento',
        'numero_visacion', 'monto'];

    /** Sin estados propios: su auditoría es la de las liquidaciones. */
    public static function moduloAuditoria(): string
    {
        return 'LIQUIDACIONES';
    }

    protected function casts(): array
    {
        return ['fecha_servicio' => 'date', 'liberada_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (LineaLiquidacion $linea) {
            if ($linea->getOriginal('liberada_en') !== null || $linea->liberada_en === null || ! in_array($linea->liberacion, [self::QUITADA, self::ANULADA], true)
                || array_diff(array_keys($linea->getDirty()), self::CAMPOS_DE_LIBERACION) !== []) {
                throw RegistroEconomicoInmutable::liquidacion();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(Liquidacion::class);
    }

    public function prestacion(): BelongsTo
    {
        return $this->belongsTo(Prestacion::class);
    }

    public function liberadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liberada_por');
    }

    public function liberada(): bool
    {
        return $this->liberada_en !== null;
    }

    public function quitada(): bool
    {
        return $this->liberacion === self::QUITADA;
    }

    public function montoEntero(): int
    {
        return (int) round((float) $this->monto);
    }
}
