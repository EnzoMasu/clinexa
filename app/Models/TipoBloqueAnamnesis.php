<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/** Tipo de bloque de la anamnesis de una consulta: Enfermedad actual, Alergias, Hábitos, ... */
class TipoBloqueAnamnesis extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'tipos_bloque_anamnesis';

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'TIPOS_BLOQUE_ANAMNESIS';
    }
}
