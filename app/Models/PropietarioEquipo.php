<?php

namespace App\Models;

use App\Models\Concerns\EsRolDePersona;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class PropietarioEquipo extends Model
{
    use EsRolDePersona, TieneEstado;

    protected $table = 'propietarios_equipo';

    protected $fillable = [
        'persona_id',
        'datos_bancarios',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'PROPIETARIOS_EQUIPO';
    }
}
