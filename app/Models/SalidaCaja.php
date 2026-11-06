<?php

namespace App\Models;

use App\Enums\AccionAuditoria;
use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una salida de dinero de la caja (docs/cobros-caja.md): un retiro, gasto o pago menor, siempre en efectivo, desde la caja abierta de
 * hoy de su cajero. Lleva categoría de gasto (el catálogo de Compras), beneficiario, comprobante (opcional), concepto y monto; su
 * egreso es un MovimientoCaja::SALIDA en negativo. VIGENTE -> ANULADO (con quién, cuándo y el motivo; el reingreso es un
 * MovimientoCaja::ANULACION_SALIDA). No se edita ni se borra: se corrige anulándola y registrando otra. `compra_id`, previsto para la
 * fase siguiente, queda vacío.
 */
class SalidaCaja extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'salidas_caja';

    public const ETIQUETAS = [Estado::VIGENTE => 'Vigente', Estado::ANULADO => 'Anulada'];

    private const CAMPOS_DE_ANULACION = ['estado_id', 'anulada_en', 'anulada_por', 'motivo_anulacion', 'updated_at'];

    protected $fillable = ['caja_id', 'categoria_gasto_id', 'beneficiario', 'comprobante', 'concepto', 'monto', 'medio_pago_id', 'usuario_id', 'registrada_en'];

    public static function moduloEstado(): string
    {
        return 'SALIDAS_CAJA';
    }

    protected function casts(): array
    {
        return [
            'registrada_en' => 'datetime',
            'anulada_en' => 'datetime',
            'monto' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SalidaCaja $salida) {
            $desde = Estado::find($salida->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($salida->estado_id)?->codigo;
            if ($desde !== Estado::VIGENTE || $hacia !== Estado::ANULADO || $salida->anulada_en === null
                || array_diff(array_keys($salida->getDirty()), self::CAMPOS_DE_ANULACION) !== []) {
                throw RegistroEconomicoInmutable::caja();
            }
        });

        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return $accion === AccionAuditoria::ANULAR ? "Anulación de la salida de caja. Motivo: {$this->motivo_anulacion}" : null;
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaGasto::class, 'categoria_gasto_id');
    }

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class);
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class);
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(SolicitudReversionCobro::class);
    }

    public function solicitudPendiente(): HasOne
    {
        return $this->hasOne(SolicitudReversionCobro::class)->whereNull('revisado_en');
    }

    public function anulada(): bool
    {
        return $this->anulada_en !== null;
    }

    public function etiquetaEstado(): string
    {
        return $this->anulada() ? self::ETIQUETAS[Estado::ANULADO] : self::ETIQUETAS[Estado::VIGENTE];
    }
}
