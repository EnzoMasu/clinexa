<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Procedimiento extends Model
{
    protected $table = 'procedimientos';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'duracion_estimada_minutos',
        'estado',
    ];
}
