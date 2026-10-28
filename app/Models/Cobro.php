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
 * Un cobro (docs/cobros-caja.md): en una caja abierta, a una paciente, siempre completo. El total es la suma exacta de las
 * prestaciones que cubre (CobroPrestacion) y la de sus líneas de pago (LineaCobro); cada línea es un movimiento de la caja. Sin
 * vuelto, saldos ni pagos parciales. VIGENTE -> REVERTIDO (una sola vez, completo, con motivo, quién y la caja del egreso:
 * Cobros\RevertirCobro); fuera de eso no se edita ni se borra. Desde la facturación (docs/facturacion.md) se registra contra una
 * factura ya emitida y solo se revierte al anularla.
 */
class Cobro extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'cobros';

    public const ETIQUETAS = [Estado::VIGENTE => 'Vigente', Estado::REVERTIDO => 'Revertido'];

    private const CAMPOS_DE_REVERSION = ['estado_id', 'revertido_en', 'revertido_por', 'motivo_reversion', 'caja_reversion_id', 'updated_at'];

    protected $fillable = ['caja_id', 'paciente_id', 'cobrado_en', 'total', 'moneda_id', 'usuario_id', 'factura_id'];

    public static function moduloEstado(): string
    {
        return 'COBROS';
    }

    protected function casts(): array
    {
        return ['cobrado_en' => 'datetime', 'revertido_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Solo VIGENTE -> REVERTIDO, con todos los datos de la reversión.
        static::updating(function (Cobro $cobro) {
            $desde = Estado::find($cobro->getOriginal('estado_id'))?->codigo;
            $hacia = Estado::find($cobro->estado_id)?->codigo;
            if ($desde !== Estado::VIGENTE || $hacia !== Estado::REVERTIDO
                || $cobro->revertido_en === null || $cobro->revertido_por === null || $cobro->caja_reversion_id === null || trim((string) $cobro->motivo_reversion) === ''
                || array_diff(array_keys($cobro->getDirty()), self::CAMPOS_DE_REVERSION) !== []) {
                throw RegistroEconomicoInmutable::cobro();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    /** La factura contra la que se registró (docs/facturacion.md); vacía en los cobros anteriores a la facturación. */
    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    public function cajero(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function moneda(): BelongsTo
    {
        return $this->belongsTo(Moneda::class);
    }

    public function prestaciones(): HasMany
    {
        return $this->hasMany(CobroPrestacion::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(LineaCobro::class);
    }

    public function revertidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revertido_por');
    }

    /** La caja donde quedó el egreso de la reversión (la abierta de quien devolvió). */
    public function cajaReversion(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_reversion_id');
    }

    public function solicitudesReversion(): HasMany
    {
        return $this->hasMany(SolicitudReversionCobro::class);
    }

    public function solicitudPendiente(): HasOne
    {
        return $this->hasOne(SolicitudReversionCobro::class)->whereNull('revisado_en');
    }

    public function detalleAuditoria(AccionAuditoria $accion): ?string
    {
        return $accion === AccionAuditoria::REVERTIR ? "Reversión del cobro. Motivo: {$this->motivo_reversion}" : null;
    }

    public function revertido(): bool
    {
        return $this->tieneEstado(Estado::REVERTIDO);
    }

    public function etiquetaEstado(): string
    {
        return self::ETIQUETAS[$this->estado?->codigo] ?? (string) $this->estado?->nombre;
    }

    public function totalTexto(): string
    {
        return $this->moneda->formatear($this->total);
    }
}
