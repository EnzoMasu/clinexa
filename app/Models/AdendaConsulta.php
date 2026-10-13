<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\Inmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota que se agrega a una consulta FINALIZADA, con su motivo, autor y fecha. Nunca se edita ni se borra.
 * Ver docs/historia-clinica.md.
 */
class AdendaConsulta extends Model
{
    use Auditable, Inmutable;

    public const MOTIVO_MINIMO = 10;

    public const MOTIVO_MAXIMO = 300;

    public const TEXTO_MAXIMO = 3000;

    protected $table = 'adendas_consulta';

    protected $fillable = ['consulta_id', 'motivo', 'texto', 'usuario_id'];

    public static function moduloEstado(): string
    {
        return 'CORRECCIONES_HC';
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(Consulta::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
