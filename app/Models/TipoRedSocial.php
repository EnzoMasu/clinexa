<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class TipoRedSocial extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'tipos_red_social';

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'TIPOS_RED_SOCIAL';
    }
}
