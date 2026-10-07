<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class CategoriaGasto extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'categorias_gasto';

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'CATEGORIAS_GASTO';
    }
}
