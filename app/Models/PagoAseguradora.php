<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pago de la aseguradora sobre una liquidación aprobada (docs/liquidaciones-aseguradoras.md): monto en guaraníes enteros, fecha,
 * medio y referencia opcional. Es una cuenta por cobrar aparte: NO pasa por Caja ni toca cajas, cierres ni cobros. VIGENTE ->
 * ANULADO (una vez, con motivo, quién y cuándo); fuera de eso no se edita ni se borra.
 */
class PagoAseguradora extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'pagos_aseguradora';

    public const ETIQUETAS = [Estado::VIGENTE => 'Vigente', Estado::ANULADO => 'Anulado'];

    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulado_en', 'anulado_por', 'motivo_anulacion', 'updated_at'];

    protected $fillable = ['liquidacion_id', 'monto', 'fecha', 'medio_pago_id', 'referencia', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'PAGOS_ASEGURADORAS';
    }

    protected function casts(): array
    {
        return ['fecha' => 'date', 'anulado_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (PagoAseguradora $pago) {
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
        return $this->belongsTo(Liquidacion::class);
    }

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class);
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

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    public function montoEntero(): int
    {
        return (int) round((float) $this->monto);
    }
}
