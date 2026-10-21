<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una línea de pago de un cobro: el medio, el monto y la referencia opcional (transferencia o POS). No se edita ni se borra. */
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

    protected static function booted(): void
    {
        static::updating(fn () => throw RegistroEconomicoInmutable::cobro());
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
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
