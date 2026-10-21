<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/** Tipo de equipo (catálogo simple, nace vacío): ecógrafo, monitor fetal, ... Ver docs/reparto-economico.md. */
class TipoEquipo extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'tipos_equipo';

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'TIPOS_EQUIPO';
    }
}
