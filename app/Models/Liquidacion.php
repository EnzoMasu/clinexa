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
 * La liquidación mensual a una aseguradora (docs/liquidaciones-aseguradoras.md): sus prestaciones firmadas hasta la fecha de corte,
 * una línea por prestación con el snapshot de la planilla, y el total (la suma exacta de sus líneas no quitadas).
 *
 * EN_PREPARACION -> PENDIENTE_APROBACION (enviada) -> APROBADO (bloqueada; "pendiente de facturar") o de vuelta a EN_PREPARACION
 * (devuelta con nota). Aprobada, los pagos de la aseguradora la pasan a PARCIAL y a PAGADO según el saldo (y anular un pago la
 * hace volver). ANULADO con motivo, solo sin pagos vigentes. Una sola abierta por aseguradora (`liquidaciones_una_abierta`). El
 * modelo rechaza cualquier otro cambio, y el borrado. El detalle de cada paso en Auditoría lo ponen los servicios (Liquidaciones\*).
 */
class Liquidacion extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'liquidaciones';

    public const ETIQUETAS = [
        Estado::EN_PREPARACION => 'En preparación', Estado::PENDIENTE_APROBACION => 'Pendiente de aprobación', Estado::APROBADO => 'Aprobada',
        Estado::PARCIAL => 'Pagada en parte', Estado::PAGADO => 'Pagada', Estado::ANULADO => 'Anulada',
    ];

    /** Abiertas: todavía se pueden quitar líneas o se están revisando. Una sola por aseguradora. */
    public const ABIERTAS = [Estado::EN_PREPARACION, Estado::PENDIENTE_APROBACION];

    /** Aprobadas (con o sin pagos): bloqueadas, reciben pagos y cuentan en lo que se le cobra a la aseguradora. */
    public const APROBADAS = [Estado::APROBADO, Estado::PARCIAL, Estado::PAGADO];

    /** La marca de la aprobación: la factura es de la fase siguiente. */
    public const PENDIENTE_DE_FACTURAR = 'PENDIENTE';

    /** Cada paso del flujo: desde => hacia => los campos que cambia. */
    private const PASOS = [
        Estado::EN_PREPARACION => [
            Estado::EN_PREPARACION => ['total'],
            Estado::PENDIENTE_APROBACION => ['estado_id', 'enviada_en', 'enviada_por'],
            Estado::ANULADO => ['estado_id', 'anulada_en', 'anulada_por', 'motivo_anulacion'],
        ],
        Estado::PENDIENTE_APROBACION => [
            Estado::APROBADO => ['estado_id', 'aprobada_en', 'aprobada_por', 'facturacion'],
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

    protected $fillable = ['responsable_pago_id', 'convenio_id', 'fecha_corte', 'total', 'moneda_id', 'armada_en', 'armada_por'];

    public static function moduloEstado(): string
    {
        return 'LIQUIDACIONES';
    }

    protected function casts(): array
    {
        return [
            'fecha_corte' => 'date',
            'armada_en' => 'datetime',
            'enviada_en' => 'datetime',
            'aprobada_en' => 'datetime',
            'devuelta_en' => 'datetime',
            'anulada_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Liquidacion $liquidacion) {
            $desde = Estado::find($liquidacion->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($liquidacion->estado_id)?->codigo;
            $permitidos = self::PASOS[$desde][$hacia] ?? null;
            if ($permitidos === null || array_diff(array_keys($liquidacion->getDirty()), [...$permitidos, 'updated_at']) !== []) {
                throw RegistroEconomicoInmutable::liquidacion();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function scopeAbiertas(Builder $query): void
    {
        $query->whereNull('liquidaciones.aprobada_en')->whereNull('liquidaciones.anulada_en');
    }

    public function scopeAprobadas(Builder $query): void
    {
        $query->whereIn('liquidaciones.estado_id', array_map(fn (string $codigo) => Estado::idDe($codigo), self::APROBADAS));
    }

    public function responsablePago(): BelongsTo
    {
        return $this->belongsTo(ResponsablePago::class);
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    /** Todas sus líneas, también las quitadas (y, en una anulada, las liberadas). */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaLiquidacion::class);
    }

    /** Las líneas que cuentan en el total: las no quitadas. En una anulada, las que tenía al anularla. */
    public function lineasVigentes(): HasMany
    {
        return $this->hasMany(LineaLiquidacion::class)->where(fn ($query) => $query->whereNull('lineas_liquidacion.liberacion')
            ->orWhere('lineas_liquidacion.liberacion', LineaLiquidacion::ANULADA));
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoAseguradora::class);
    }

    public function pagosVigentes(): HasMany
    {
        return $this->hasMany(PagoAseguradora::class)->whereNull('pagos_aseguradora.anulado_en');
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

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    public function totalEntero(): int
    {
        return (int) round((float) $this->total);
    }

    /** Lo pagado: la suma de los pagos vigentes. Usa `pagos_vigentes_sum_monto` (withSum) si se cargó. */
    public function pagado(): int
    {
        $suma = $this->pagos_vigentes_sum_monto ?? $this->pagosVigentes()->sum('monto');

        return (int) round((float) $suma);
    }

    /** El saldo: total - pagos vigentes. Se calcula, no se guarda. Cero en una que no está aprobada. */
    public function saldo(): int
    {
        return $this->aprobada() ? $this->totalEntero() - $this->pagado() : 0;
    }

    public function aseguradoraTexto(): string
    {
        return (string) $this->responsablePago?->persona?->nombre_completo;
    }

    public function formatear(int|float|string $monto): string
    {
        return $this->moneda->formatear($monto);
    }
}
