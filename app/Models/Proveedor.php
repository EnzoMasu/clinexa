<?php

namespace App\Models;

use App\Models\Concerns\EsRolDePersona;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    use EsRolDePersona, TieneEstado;

    protected $table = 'proveedores';

    protected $fillable = [
        'persona_id',
        'condiciones_comerciales',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'PROVEEDORES';
    }
}
