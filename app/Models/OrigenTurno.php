<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/** Por dónde se pidió el turno: PRESENCIAL, TELEFONICO, WEB, APP (catálogo, no enum). */
class OrigenTurno extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'origenes_turno';

    protected $fillable = [
        'codigo',
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'ORIGENES_TURNO';
    }
}
