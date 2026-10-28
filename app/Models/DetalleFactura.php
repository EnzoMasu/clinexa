<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de una factura (docs/facturacion.md): una prestación, con la descripción del procedimiento, cantidad 1, el precio con IVA
 * incluido (lo que paga la paciente), la tasa del procedimiento al emitir (snapshot: 10, 5 o 0 = exenta), el gravado y el IVA. Solo se
 * libera (una vez) al anular la factura: la prestación vuelve a quedar libre. Una prestación, en una sola línea no liberada
 * (`detalles_factura_una_por_prestacion`). No se borra.
 */
class DetalleFactura extends Model
{
    use Auditable;

    protected $table = 'detalles_factura';

    protected $fillable = ['factura_id', 'prestacion_id', 'descripcion', 'cantidad', 'precio_unitario', 'monto', 'tasa_iva', 'gravado', 'iva'];

    /** Sin estados propios: su auditoría es la de las facturas. */
    public static function moduloAuditoria(): string
    {
        return 'FACTURAS';
    }

    protected function casts(): array
    {
        return ['liberada_en' => 'datetime', 'tasa_iva' => 'integer', 'cantidad' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (DetalleFactura $detalle) {
            if ($detalle->getOriginal('liberada_en') !== null || $detalle->liberada_en === null
                || array_diff(array_keys($detalle->getDirty()), ['liberada_en', 'updated_at']) !== []) {
                throw RegistroEconomicoInmutable::factura();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    public function prestacion(): BelongsTo
    {
        return $this->belongsTo(Prestacion::class);
    }

    public function entero(string $columna): int
    {
        return (int) round((float) $this->{$columna});
    }

    public function tasaTexto(): string
    {
        return self::textoTasa($this->tasa_iva);
    }

    public static function textoTasa(int $tasa): string
    {
        return $tasa === 0 ? 'Exenta' : "{$tasa} %";
    }
}
