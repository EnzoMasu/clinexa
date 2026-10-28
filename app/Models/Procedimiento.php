<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class Procedimiento extends Model
{
    use Auditable, TieneEstado;

    protected $table = 'procedimientos';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'duracion_estimada_minutos',
        'estado_id',
        'tasa_iva',
    ];

    /** Tasas de IVA (docs/facturacion.md): precios con IVA incluido; 0 = exenta. */
    public const TASAS_IVA = [10 => 'IVA 10 %', 5 => 'IVA 5 %', 0 => 'Exenta'];

    protected function casts(): array
    {
        return ['tasa_iva' => 'integer'];
    }

    public static function moduloEstado(): string
    {
        return 'PROCEDIMIENTOS';
    }
}
