<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sucursal extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'sucursales';

    protected $fillable = [
        'nombre',
        'direccion',
        'telefono',
        'estado_id',
        'ciudad_id',
        'clinica_id',
    ];

    public static function moduloEstado(): string
    {
        return 'SUCURSALES';
    }

    /** La clínica como contribuyente emisor (docs/facturacion.md). Su dirección y teléfono son los del establecimiento. */
    public function clinica(): BelongsTo
    {
        return $this->belongsTo(Clinica::class);
    }

    /** La sucursal principal: la activa más antigua (la de las recetas, los PDF y la facturación con un solo punto). */
    public static function principal(): ?self
    {
        return self::activos()->orderBy('id')->first();
    }

    /**
     * Ciudad (opcional): dato adicional a la dirección en texto libre.
     */
    public function ciudad(): BelongsTo
    {
        return $this->belongsTo(Ciudad::class);
    }
}
