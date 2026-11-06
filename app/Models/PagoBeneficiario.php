<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pago a un beneficiario sobre su liquidación aprobada (docs/liquidacion-beneficiarios.md): monto, fecha, medio del catálogo y
 * comprobante (obligatorio según el medio). Aparte de la caja de cobros: sin caja, solo queda registrado; en efectivo desde una caja
 * abierta de hoy (la propia de Tesorería), sale como una salida de esa caja (`salida_caja_id`, con su categoría de gasto) y se anula con
 * las reglas de la salida. `cuenta_por_pagar_id`, vacío, previsto para el objetivo 12. VIGENTE -> ANULADO (una vez, con motivo); fuera
 * de eso no se edita ni se borra.
 */
class PagoBeneficiario extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'pagos_beneficiario';

    public const ETIQUETAS = [Estado::VIGENTE => 'Vigente', Estado::ANULADO => 'Anulado'];

    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulado_en', 'anulado_por', 'motivo_anulacion', 'updated_at'];

    protected $fillable = ['liquidacion_beneficiario_id', 'monto', 'fecha', 'medio_pago_id', 'referencia', 'caja_id', 'salida_caja_id',
        'categoria_gasto_id', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'PAGOS_BENEFICIARIOS';
    }

    protected function casts(): array
    {
        return ['fecha' => 'date', 'anulado_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (PagoBeneficiario $pago) {
            $desde = Estado::find($pago->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($pago->estado_id)?->codigo;
            if ($desde !== Estado::VIGENTE || $hacia !== Estado::ANULADO || $pago->anulado_en === null || trim((string) $pago->motivo_anulacion) === ''
                || array_diff(array_keys($pago->getDirty()), self::CAMPOS_DE_ANULACION) !== []) {
                throw RegistroEconomicoInmutable::liquidacion();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionBeneficiario::class, 'liquidacion_beneficiario_id');
    }

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class);
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaCaja::class, 'salida_caja_id');
    }

    public function categoriaGasto(): BelongsTo
    {
        return $this->belongsTo(CategoriaGasto::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function anulado(): bool
    {
        return $this->anulado_en !== null;
    }

    public function conCaja(): bool
    {
        return $this->salida_caja_id !== null;
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    public function montoEntero(): int
    {
        return (int) round((float) $this->monto);
    }
}
