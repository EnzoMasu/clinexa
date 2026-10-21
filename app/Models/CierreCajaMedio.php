<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un medio de pago de un cierre de caja: lo esperado, lo contado y la diferencia (contado - esperado). Una foto: no cambia. */
class CierreCajaMedio extends Model
{
    use Auditable;

    protected $table = 'cierre_caja_medios';

    protected $fillable = ['cierre_caja_id', 'medio_pago_id', 'esperado', 'contado', 'diferencia'];

    /** Sin estados propios: su auditoría es la de los cierres. */
    public static function moduloAuditoria(): string
    {
        return 'CIERRES_CAJA';
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw RegistroEconomicoInmutable::caja());
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function cierre(): BelongsTo
    {
        return $this->belongsTo(CierreCaja::class, 'cierre_caja_id');
    }

    public function medioPago(): BelongsTo
    {
        return $this->belongsTo(MedioPago::class);
    }
}
