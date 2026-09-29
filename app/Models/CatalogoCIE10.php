<?php

namespace App\Models;

use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class CatalogoCIE10 extends Model
{
    use TieneEstado;

    protected $table = 'catalogo_cie10';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'descripcion',
        'capitulo',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'CIE10';
    }
}
