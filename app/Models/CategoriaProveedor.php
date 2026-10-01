<?php

namespace App\Models;

use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class CategoriaProveedor extends Model
{
    use TieneEstado;

    protected $table = 'categorias_proveedor';

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'CATEGORIAS_PROVEEDOR';
    }
}
