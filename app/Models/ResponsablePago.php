<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\EsRolDePersona;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;

class ResponsablePago extends Model
{
    use Auditable, EsRolDePersona, TieneEstado;

    protected $table = 'responsables_pago';

    protected $fillable = [
        'persona_id',
        'limite_credito',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'RESPONSABLES_PAGO';
    }

    protected function casts(): array
    {
        return [
            'limite_credito' => 'decimal:2',
        ];
    }
}
