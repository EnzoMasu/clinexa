<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

/**
 * Dieta del catálogo (docs/indicaciones-generales.md): nombre y texto. Se ofrece en la indicación general con "Insertar
 * dieta", que COPIA el texto: la indicación no queda enlazada, así que cambiar o desactivar la dieta no altera lo guardado.
 * No se borra: se desactiva.
 */
class Dieta extends Model
{
    use Auditable, TieneEstado;

    /** Mismo tope que el texto de una indicación general (Indicacion::MAXIMO_DESCRIPCION): la dieta sola siempre entra al copiarla. */
    public const MAXIMO_TEXTO = 3000;

    protected $table = 'dietas';

    protected $fillable = [
        'nombre',
        'texto',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'DIETAS';
    }
}
