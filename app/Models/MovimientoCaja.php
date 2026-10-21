<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento de la caja, por medio de pago y con signo (docs/cobros-caja.md): APERTURA (el monto inicial, en efectivo) y COBRO
 * (una línea de cobro). Lo esperado al cerrar es la suma por medio; los egresos y las reversiones de fases futuras entran como
 * movimientos negativos. Se deriva de la apertura y de las líneas de cobro, que ya se auditan; no se edita ni se borra.
 */
class MovimientoCaja extends Model
{
    public const APERTURA = 'APERTURA';

    public const COBRO = 'COBRO';

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

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class);
    }
}
