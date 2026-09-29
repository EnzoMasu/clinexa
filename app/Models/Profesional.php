<?php

namespace App\Models;

use App\Models\Concerns\EsRolDePersona;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Profesional extends Model
{
    use EsRolDePersona, TieneEstado;

    protected $table = 'profesionales';

    protected $fillable = [
        'persona_id',
        'matricula',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'PROFESIONALES';
    }

    public static function tiposPersona(): array
    {
        return ['FISICA'];
    }

    /**
     * Especialidades del profesional, con la matrícula de la especialidad y desde cuándo la ejerce.
     */
    public function especialidades(): BelongsToMany
    {
        return $this->belongsToMany(Especialidad::class, 'profesional_especialidad', 'profesional_id', 'especialidad_id')
            ->withPivot('nro_matricula_especialidad', 'fecha_desde');
    }
}
