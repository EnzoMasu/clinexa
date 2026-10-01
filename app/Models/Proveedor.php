<?php

namespace App\Models;

use App\Models\Concerns\EsRolDePersona;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Proveedor extends Model
{
    use EsRolDePersona, TieneEstado;

    protected $table = 'proveedores';

    protected $fillable = [
        'persona_id',
        'condiciones_comerciales',
        'datos_bancarios',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'PROVEEDORES';
    }

    /** Rubros del proveedor (insumos médicos, equipos médicos, ...): puede tener varios. */
    public function categorias(): BelongsToMany
    {
        return $this->belongsToMany(CategoriaProveedor::class, 'proveedor_categoria', 'proveedor_id', 'categoria_proveedor_id');
    }
}
