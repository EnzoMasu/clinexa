<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea de pago de un cobro: el medio, el monto y la referencia: el comprobante del pago (código de la transferencia,
 * voucher del POS, boleta del depósito o número del cheque), obligatorio según el medio en los cobros nuevos. Solo el comprobante se corrige
 * (`corregirComprobante`, auditado); el resto no se edita ni se borra. */
class LineaCobro extends Model
{
    use Auditable;

    protected $table = 'lineas_cobro';

    protected $fillable = ['cobro_id', 'medio_pago_id', 'monto', 'referencia'];

    /** Sin estados propios: su auditoría es la de los cobros. */
    public static function moduloAuditoria(): string
    {
        return 'COBROS';
    }

    /** Solo `corregirComprobante` lo enciende: la única edición que admite una línea. */
    private bool $corrigiendoComprobante = false;

    protected static function booted(): void
    {
        // Lo único que cambia es la referencia (el comprobante), y solo por Cobros\CorregirComprobante.
        static::updating(function (LineaCobro $linea) {
            if (! $linea->corrigiendoComprobante || array_diff(array_keys($linea->getDirty()), ['referencia', 'updated_at']) !== []) {
                throw RegistroEconomicoInmutable::cobro();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    /** Corrige el número de comprobante (la referencia); el medio, el monto y el cobro no cambian. Lo audita el trait. */
    public function corregirComprobante(?string $comprobante): void
    {
        $this->corrigiendoComprobante = true;
        try {
            $this->referencia = $comprobante;
            $this->save();
        } finally {
            $this->corrigiendoComprobante = false;
        }
    }

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class);
    }
}
