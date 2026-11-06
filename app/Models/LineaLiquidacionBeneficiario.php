<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Support\Repartos\Porcentaje;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la liquidación a un beneficiario (docs/liquidacion-beneficiarios.md):
 *
 * - ORDINARIA: lo que le corresponde de una línea del reparto (`linea_reparto_id`, con su prestación), con el snapshot: fecha de
 *   servicio, paciente (solo en la del profesional), procedimiento, equipo, monto base, porcentaje y monto. `vez` cuenta las veces que
 *   esa línea del reparto entró (otra vez solo después de un ajuste aprobado: cobro revertido y vuelto a cobrar).
 * - AJUSTE: en contra (monto negativo) de una ORDINARIA ya aprobada que dejó de corresponder (`ajusta_linea_id`, con el motivo).
 * - SALDO_ANTERIOR: el saldo negativo de una liquidación aprobada anterior del mismo beneficiario (`saldo_de_liquidacion_id`).
 *
 * Nunca se edita ni se borra: solo se libera una vez, QUITADA (con motivo, en preparación) o ANULADA (con la liquidación).
 */
class LineaLiquidacionBeneficiario extends Model
{
    use Auditable;

    protected $table = 'lineas_liquidacion_beneficiario';

    public const ORDINARIA = 'ORDINARIA';

    public const AJUSTE = 'AJUSTE';

    public const SALDO_ANTERIOR = 'SALDO_ANTERIOR';

    public const TIPOS = [self::ORDINARIA => 'Prestación', self::AJUSTE => 'Ajuste en contra', self::SALDO_ANTERIOR => 'Saldo anterior en contra'];

    public const QUITADA = 'QUITADA';

    public const ANULADA = 'ANULADA';

    private const CAMPOS_DE_LIBERACION = ['liberacion', 'liberada_en', 'liberada_por', 'motivo_liberacion', 'updated_at'];

    protected $fillable = ['liquidacion_beneficiario_id', 'tipo', 'linea_reparto_id', 'prestacion_id', 'vez', 'ajusta_linea_id', 'saldo_de_liquidacion_id',
        'fecha_servicio', 'paciente', 'procedimiento', 'equipo', 'monto_base', 'porcentaje', 'monto', 'motivo_ajuste'];

    public static function moduloAuditoria(): string
    {
        return 'LIQUIDACIONES_BENEFICIARIOS';
    }

    protected function casts(): array
    {
        return ['fecha_servicio' => 'date', 'liberada_en' => 'datetime', 'porcentaje' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(function (LineaLiquidacionBeneficiario $linea) {
            if ($linea->getOriginal('liberada_en') !== null || $linea->liberada_en === null || ! in_array($linea->liberacion, [self::QUITADA, self::ANULADA], true)
                || array_diff(array_keys($linea->getDirty()), self::CAMPOS_DE_LIBERACION) !== []) {
                throw RegistroEconomicoInmutable::liquidacion();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionBeneficiario::class, 'liquidacion_beneficiario_id');
    }

    public function lineaReparto(): BelongsTo
    {
        return $this->belongsTo(LineaReparto::class);
    }

    public function prestacion(): BelongsTo
    {
        return $this->belongsTo(Prestacion::class);
    }

    public function ajustaA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ajusta_linea_id');
    }

    public function saldoDe(): BelongsTo
    {
        return $this->belongsTo(LiquidacionBeneficiario::class, 'saldo_de_liquidacion_id');
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

    public function ordinaria(): bool
    {
        return $this->tipo === self::ORDINARIA;
    }

    public function ajuste(): bool
    {
        return $this->tipo === self::AJUSTE;
    }

    public function tipoTexto(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function montoEntero(): int
    {
        return (int) round((float) $this->monto);
    }

    public function porcentajeTexto(): string
    {
        return $this->porcentaje === null ? '' : Porcentaje::texto($this->porcentaje);
    }
}
