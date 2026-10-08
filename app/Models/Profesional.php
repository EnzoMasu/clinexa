<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\EsRolDePersona;
use App\Models\Concerns\TieneEstado;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profesional extends Model
{
    use Auditable, EsRolDePersona, TieneEstado;

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
    /** En el log, cada especialidad con sus datos: "Ecografía (desde 01/03/2015, matrícula GO-77)". */
    public function relacionesAuditadas(): array
    {
        return [
            'especialidades' => fn (Collection $especialidades) => $especialidades
                ->map(fn (Especialidad $especialidad) => sprintf('%s (desde %s%s%s)',
                    $especialidad->nombre,
                    Fecha::mostrar($especialidad->pivot->fecha_desde),
                    $especialidad->pivot->nro_matricula_especialidad ? ', matrícula '.$especialidad->pivot->nro_matricula_especialidad : '',
                    $especialidad->pivot->activa ? '' : ', deshabilitada'))
                ->sort()->values()->all(),
        ];
    }

    public function consultas(): HasMany
    {
        return $this->hasMany(Consulta::class);
    }

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
