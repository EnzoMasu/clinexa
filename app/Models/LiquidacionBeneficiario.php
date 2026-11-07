<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La liquidación a un beneficiario del reparto (docs/liquidacion-beneficiarios.md): un profesional (lo suyo como tratante) o el dueño
 * de un equipo (un proveedor, todas sus máquinas). Sus líneas: lo que le corresponde de cada prestación ya percibida (ORDINARIA), los
 * ajustes en contra de lo ya aprobado que dejó de corresponder (AJUSTE) y el saldo negativo de la anterior (SALDO_ANTERIOR). El total es
 * la suma exacta de sus líneas no quitadas y puede ser negativo: entonces no se paga y pasa a la siguiente.
 *
 * EN_PREPARACION -> PENDIENTE_APROBACION -> APROBADO (o devuelta a EN_PREPARACION); aprobada, los pagos la pasan a PARCIAL y a PAGADO
 * (anular un pago la hace volver). ANULADO con motivo. Una sola abierta por beneficiario. El modelo rechaza cualquier otro cambio, y el
 * borrado. El detalle de cada paso en Auditoría lo ponen los servicios (LiquidacionesBeneficiarios\*).
 */
class LiquidacionBeneficiario extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'liquidaciones_beneficiario';

    public const PROFESIONAL = 'PROFESIONAL';

    public const EQUIPO = 'EQUIPO';

    public const BENEFICIARIOS = [self::PROFESIONAL => 'Profesional', self::EQUIPO => 'Dueño de equipo'];

    public const ETIQUETAS = Liquidacion::ETIQUETAS;

    public const ABIERTAS = Liquidacion::ABIERTAS;

    public const APROBADAS = Liquidacion::APROBADAS;

    private const PASOS = [
        Estado::EN_PREPARACION => [
            Estado::EN_PREPARACION => ['total'],
            Estado::PENDIENTE_APROBACION => ['estado_id', 'enviada_en', 'enviada_por'],
            Estado::ANULADO => ['estado_id', 'anulada_en', 'anulada_por', 'motivo_anulacion'],
        ],
        Estado::PENDIENTE_APROBACION => [
            Estado::APROBADO => ['estado_id', 'aprobada_en', 'aprobada_por'],
            Estado::EN_PREPARACION => ['estado_id', 'devuelta_en', 'devuelta_por', 'nota_devolucion'],
            Estado::ANULADO => ['estado_id', 'anulada_en', 'anulada_por', 'motivo_anulacion'],
        ],
        Estado::APROBADO => [
            Estado::PARCIAL => ['estado_id'], Estado::PAGADO => ['estado_id'],
            Estado::ANULADO => ['estado_id', 'anulada_en', 'anulada_por', 'motivo_anulacion'],
        ],
        Estado::PARCIAL => [Estado::APROBADO => ['estado_id'], Estado::PARCIAL => [], Estado::PAGADO => ['estado_id']],
        Estado::PAGADO => [Estado::APROBADO => ['estado_id'], Estado::PARCIAL => ['estado_id']],
    ];

    protected $fillable = ['beneficiario', 'profesional_id', 'proveedor_id', 'fecha_corte', 'total', 'moneda_id', 'armada_en', 'armada_por'];

    public static function moduloEstado(): string
    {
        return 'LIQUIDACIONES_BENEFICIARIOS';
    }

    protected function casts(): array
    {
        return [
            'fecha_corte' => 'date', 'armada_en' => 'datetime', 'enviada_en' => 'datetime', 'aprobada_en' => 'datetime',
            'devuelta_en' => 'datetime', 'anulada_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LiquidacionBeneficiario $liquidacion) {
            $valida = match ($liquidacion->beneficiario) {
                self::PROFESIONAL => $liquidacion->profesional_id !== null && $liquidacion->proveedor_id === null,
                self::EQUIPO => $liquidacion->proveedor_id !== null && $liquidacion->profesional_id === null,
                default => false,
            };
            if (! $valida) {
                throw new \LogicException('La liquidación necesita un beneficiario válido.');
            }
        });
        static::updating(function (LiquidacionBeneficiario $liquidacion) {
            $desde = Estado::find($liquidacion->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($liquidacion->estado_id)?->codigo;
            $permitidos = self::PASOS[$desde][$hacia] ?? null;
            if ($permitidos === null || array_diff(array_keys($liquidacion->getDirty()), [...$permitidos, 'updated_at']) !== []) {
                throw RegistroEconomicoInmutable::liquidacion();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    /** Las de ese beneficiario (PROFESIONAL con el profesional, EQUIPO con el proveedor). */
    public function scopeDelBeneficiario(Builder $query, string $tipo, int $id): void
    {
        $query->where('liquidaciones_beneficiario.beneficiario', $tipo)
            ->where($tipo === self::PROFESIONAL ? 'liquidaciones_beneficiario.profesional_id' : 'liquidaciones_beneficiario.proveedor_id', $id);
    }

    public function scopeAbiertas(Builder $query): void
    {
        $query->whereNull('liquidaciones_beneficiario.aprobada_en')->whereNull('liquidaciones_beneficiario.anulada_en');
    }

    public function scopeAprobadas(Builder $query): void
    {
        $query->whereIn('liquidaciones_beneficiario.estado_id', array_map(fn (string $codigo) => Estado::idDe($codigo), self::APROBADAS));
    }

    public function profesional(): BelongsTo
    {
        return $this->belongsTo(Profesional::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(LineaLiquidacionBeneficiario::class);
    }

    /** Las que cuentan en el total: las no quitadas. En una anulada, las que tenía al anularla. */
    public function lineasVigentes(): HasMany
    {
        return $this->hasMany(LineaLiquidacionBeneficiario::class)->where(fn ($query) => $query->whereNull('lineas_liquidacion_beneficiario.liberacion')
            ->orWhere('lineas_liquidacion_beneficiario.liberacion', LineaLiquidacionBeneficiario::ANULADA));
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoBeneficiario::class);
    }

    public function pagosVigentes(): HasMany
    {
        return $this->hasMany(PagoBeneficiario::class)->whereNull('pagos_beneficiario.anulado_en');
    }

    public function armadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'armada_por');
    }

    public function enviadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviada_por');
    }

    public function aprobadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobada_por');
    }

    public function devueltaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'devuelta_por');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    public function enPreparacion(): bool
    {
        return $this->tieneEstado(Estado::EN_PREPARACION);
    }

    public function pendienteDeAprobacion(): bool
    {
        return $this->tieneEstado(Estado::PENDIENTE_APROBACION);
    }

    public function anulada(): bool
    {
        return $this->tieneEstado(Estado::ANULADO);
    }

    public function abierta(): bool
    {
        return in_array($this->estado?->codigo, self::ABIERTAS, true);
    }

    public function aprobada(): bool
    {
        return in_array($this->estado?->codigo, self::APROBADAS, true);
    }

    public function deProfesional(): bool
    {
        return $this->beneficiario === self::PROFESIONAL;
    }

    public function etiquetaEstado(): string
    {
        $etiqueta = self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
        if ($this->tieneEstado(Estado::APROBADO)) {
            $total = $this->totalEntero();
            if ($total < 0) {
                return "{$etiqueta} (saldo en contra)";
            }
            if ($total === 0) {
                return "{$etiqueta} (sin saldo a pagar)";
            }
        }

        return $etiqueta;
    }

    public function totalEntero(): int
    {
        return (int) round((float) $this->total);
    }

    /** Lo pagado: la suma de los pagos vigentes. Usa `pagos_vigentes_sum_monto` (withSum) si se cargó. */
    public function pagado(): int
    {
        return (int) round((float) ($this->pagos_vigentes_sum_monto ?? $this->pagosVigentes()->sum('monto')));
    }

    /** El saldo a pagar: total - pagos vigentes, nunca negativo. Cero en una no aprobada o con total negativo (pasa a la siguiente). */
    public function saldo(): int
    {
        return $this->aprobada() ? max(0, $this->totalEntero() - $this->pagado()) : 0;
    }

    /** El saldo en contra que pasa a la siguiente (positivo), o cero. */
    public function saldoEnContra(): int
    {
        return $this->aprobada() && $this->totalEntero() < 0 ? -1 * $this->totalEntero() : 0;
    }

    public function beneficiarioTexto(): string
    {
        return $this->deProfesional()
            ? (string) $this->profesional?->persona?->nombre_completo
            : (string) $this->proveedor?->persona?->nombre_completo;
    }

    public function tipoTexto(): string
    {
        return self::BENEFICIARIOS[$this->beneficiario] ?? $this->beneficiario;
    }

    public function formatear(int|float|string $monto): string
    {
        return $this->moneda->formatear($monto);
    }
}
