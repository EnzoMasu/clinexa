<?php

namespace App\Models;

use App\Exceptions\RegistroEconomicoInmutable;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una prestación cubierta por un cobro, con su monto. Vigente mientras no tenga `revertido_en` (lo pone la reversión del cobro, una
 * vez): a lo sumo una vigente por prestación (`cobro_prestaciones_un_cobro_vigente`). Fuera de eso no se edita ni se borra.
 */
class CobroPrestacion extends Model
{
    use Auditable;

    protected $table = 'cobro_prestaciones';

    protected $fillable = ['cobro_id', 'prestacion_id', 'monto'];

    protected function casts(): array
    {
        return ['revertido_en' => 'datetime'];
    }

    /** Sin estados propios: su auditoría es la de los cobros. */
    public static function moduloAuditoria(): string
    {
        return 'COBROS';
    }

    protected static function booted(): void
    {
        static::updating(function (CobroPrestacion $cubierta) {
            if ($cubierta->getOriginal('revertido_en') !== null || $cubierta->revertido_en === null
                || array_diff(array_keys($cubierta->getDirty()), ['revertido_en', 'updated_at']) !== []) {
                throw RegistroEconomicoInmutable::cobro();
            }
        });
        static::deleting(fn () => throw RegistroEconomicoInmutable::borrado());
    }

    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('cobro_prestaciones.revertido_en');
    }

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    public function prestacion(): BelongsTo
    {
        return $this->belongsTo(Prestacion::class);
    }
}
