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
    ];

    public static function moduloEstado(): string
    {
        return 'SUCURSALES';
    }

    /**
     * Ciudad (opcional): dato adicional a la dirección en texto libre.
     */
    public function ciudad(): BelongsTo
    {
        return $this->belongsTo(Ciudad::class);
    }
}
