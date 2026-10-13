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

    public const PRESENCIAL = 'PRESENCIAL';

    /** El origen PRESENCIAL (el de toda urgencia), buscado por código. */
    public static function presencial(): self
    {
        return self::where('codigo', self::PRESENCIAL)->first()
            ?? throw new \RuntimeException('Falta el origen de turno con código PRESENCIAL (Orígenes de turno): sin él no se puede registrar una urgencia.');
    }

    public static function moduloEstado(): string
    {
        return 'ORIGENES_TURNO';
    }
}
