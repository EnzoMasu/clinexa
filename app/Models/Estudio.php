<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/**
 * Estudio del catálogo (docs/ordenes-estudio.md): solo el nombre. Se ofrece en la orden de estudio, que COPIA el nombre a su
 * snapshot: cambiar o desactivar el estudio no altera las órdenes ya emitidas. Nace vacío; no se borra, se desactiva.
 */
class Estudio extends Model
{
    use Auditable, TieneEstado;

    public const MAXIMO_NOMBRE = 150;

    protected $table = 'estudios';

    protected $fillable = [
        'nombre',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'ESTUDIOS';
    }
}
