<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento de la caja, por medio de pago y con signo (docs/cobros-caja.md): APERTURA (el monto inicial, en efectivo), COBRO
 * (una línea de cobro), REVERSION (el egreso, en negativo, de una línea de un cobro revertido, en la caja abierta de quien devuelve),
 * SALIDA (el egreso en efectivo, en negativo, de una salida de caja) y ANULACION_SALIDA (el reingreso, en positivo, que compensa una
 * salida anulada). Lo esperado al cerrar es la suma por medio. Se deriva de la apertura, de las líneas de cobro, de la reversión y de
 * la salida, que ya se auditan;
 * no se edita ni se borra. Única excepción: el medio del movimiento de un cobro sigue al de su línea cuando se corrige en un arqueo
 * devuelto (`cambiarMedio`, desde LineaCobro::corregirPago, que audita la línea).
 */
class MovimientoCaja extends Model
{
    public const APERTURA = 'APERTURA';

    public const COBRO = 'COBRO';

    public const REVERSION = 'REVERSION';

    public const SALIDA = 'SALIDA';

    public const ANULACION_SALIDA = 'ANULACION_SALIDA';

    protected $table = 'movimientos_caja';

    protected $fillable = ['caja_id', 'tipo', 'medio_pago_id', 'monto', 'cobro_id', 'linea_cobro_id', 'salida_caja_id', 'usuario_id', 'registrado_en'];

    protected function casts(): array
    {
        return ['registrado_en' => 'datetime'];
    }

    private bool $cambiandoMedio = false;

    protected static function booted(): void
    {
        static::updating(function (MovimientoCaja $movimiento) {
            if (! $movimiento->cambiandoMedio || $movimiento->tipo !== self::COBRO
                || array_diff(array_keys($movimiento->getDirty()), ['medio_pago_id', 'updated_at']) !== []) {
                throw RegistroEconomicoInmutable::caja();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function cambiarMedio(int $medioPagoId): void
    {
        $this->cambiandoMedio = true;
        try {
            $this->medio_pago_id = $medioPagoId;
            $this->save();
        } finally {
            $this->cambiandoMedio = false;
        }
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    public function salida(): BelongsTo
    {
        return $this->belongsTo(SalidaCaja::class, 'salida_caja_id');
    }

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class);
    }
}
