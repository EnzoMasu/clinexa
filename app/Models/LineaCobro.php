<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea de pago de un cobro: el medio, el monto y la referencia: el comprobante del pago (código de la transferencia,
 * voucher del POS, boleta del depósito o número del cheque), obligatorio según el medio en los cobros nuevos. Solo el comprobante y el
 * medio se corrigen (`corregirPago`, desde Cobros\CorregirComprobante en un arqueo devuelto, auditado); el monto no se edita y nada se borra. */
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

    /** Solo `corregirPago` lo enciende: la única edición que admite una línea. */
    private bool $corrigiendoPago = false;

    protected static function booted(): void
    {
        // Lo único que cambia es el comprobante (la referencia) y el medio, y solo por Cobros\CorregirComprobante.
        static::updating(function (LineaCobro $linea) {
            if (! $linea->corrigiendoPago || array_diff(array_keys($linea->getDirty()), ['referencia', 'medio_pago_id', 'updated_at']) !== []) {
                throw RegistroEconomicoInmutable::cobro();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    /**
     * Corrige el medio y el comprobante (la referencia); el monto y el cobro no cambian. Lo audita el trait (valor anterior y nuevo).
     * El movimiento de la caja de la línea pasa al mismo medio: lo esperado por medio se recalcula solo.
     */
    public function corregirPago(int $medioPagoId, ?string $comprobante): void
    {
        $this->corrigiendoPago = true;
        try {
            $this->medio_pago_id = $medioPagoId;
            $this->referencia = $comprobante;
            $this->save();
            MovimientoCaja::where('linea_cobro_id', $this->id)->where('tipo', MovimientoCaja::COBRO)->get()
                ->each(fn (MovimientoCaja $movimiento) => $movimiento->cambiarMedio($medioPagoId));
        } finally {
            $this->corrigiendoPago = false;
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
