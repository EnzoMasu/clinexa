<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/** Tipo de una indicación general de la consulta: Reposo, Dieta, Control, General. */
class TipoIndicacion extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'tipos_indicacion';

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'TIPOS_INDICACION';
    }
}
