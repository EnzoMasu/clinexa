<?php

namespace App\Models;

use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class MedioPago extends Model
{
    use TieneEstado;

    protected $table = 'medios_pago';

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'MEDIOS_PAGO';
    }
}
