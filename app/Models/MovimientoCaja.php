<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento de la caja, por medio de pago y con signo (docs/cobros-caja.md): APERTURA (el monto inicial, en efectivo), COBRO
 * (una línea de cobro) y REVERSION (el egreso, en negativo, de una línea de un cobro revertido, en la caja abierta de quien devuelve).
 * Lo esperado al cerrar es la suma por medio. Se deriva de la apertura, de las líneas de cobro y de la reversión, que ya se auditan;
 * no se edita ni se borra.
 */
class MovimientoCaja extends Model
{
    public const APERTURA = 'APERTURA';

    public const COBRO = 'COBRO';

    public const REVERSION = 'REVERSION';

    protected $table = 'movimientos_caja';

    protected $fillable = ['caja_id', 'tipo', 'medio_pago_id', 'monto', 'cobro_id', 'linea_cobro_id', 'usuario_id', 'registrado_en'];

    protected function casts(): array
    {
        return ['registrado_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw RegistroEconomicoInmutable::caja());
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
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
