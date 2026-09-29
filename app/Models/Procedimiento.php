<?php

namespace App\Models;

use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class Procedimiento extends Model
{
    use TieneEstado;

    protected $table = 'procedimientos';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'duracion_estimada_minutos',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'PROCEDIMIENTOS';
    }
}
