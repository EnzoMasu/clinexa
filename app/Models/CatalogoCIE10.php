<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogoCIE10 extends Model
{
    protected $table = 'catalogo_cie10';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'descripcion',
        'capitulo',
    ];
}
