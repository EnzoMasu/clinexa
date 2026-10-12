<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/** Cargo del personal de salud (catálogo): Médico/a, Enfermero/a, Licenciado/a en obstetricia, ... */
class CargoSalud extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'cargos_salud';

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'CARGOS_SALUD';
    }
}
