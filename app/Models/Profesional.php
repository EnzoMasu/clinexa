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
     * Todas las especialidades del profesional (habilitadas y deshabilitadas), con la matrícula de
     * la especialidad, desde cuándo la ejerce y si está activa. Para administrarlas (formulario).
     */
    public function especialidades(): BelongsToMany
    {
        return $this->belongsToMany(Especialidad::class, 'profesional_especialidad', 'profesional_id', 'especialidad_id')
            ->withPivot('nro_matricula_especialidad', 'fecha_desde', 'activa');
    }

    /**
     * Especialidades que el profesional ejerce hoy: las habilitadas (pivote activa) y cuyo
     * catálogo está ACTIVO. Es la que debe usar cualquier otra parte del sistema (agenda, turnos,
     * etc.) para ofrecer las especialidades del profesional.
     */
    public function especialidadesActivas(): BelongsToMany
    {
        return $this->especialidades()
            ->wherePivot('activa', true)
            ->where('especialidades.estado_id', Estado::idDe(Estado::ACTIVO));
    }
}
