<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\EsRolDePersona;
use App\Models\Concerns\TieneEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rol "Personal de salud" sobre una Persona física: enfermería, obstetricia, auxiliares... Con el rol ACTIVO (o
 * siendo Profesional activo) una persona puede cargar el triaje (ConsultaPolicy::puedeCargarTriaje), además del
 * permiso del módulo. No es contenido clínico. Nunca se borra: se desactiva.
 */
class PersonalSalud extends Model
{
    use Auditable, EsRolDePersona, TieneEstado;

    protected $table = 'personal_salud';

    protected $fillable = [
        'persona_id',
        'cargo_salud_id',
        'registro_profesional',
        'estado_id',
    ];

    public static function moduloEstado(): string
    {
        return 'PERSONAL_SALUD';
    }

    public static function tiposPersona(): array
    {
        return ['FISICA'];
    }

    public function cargoSalud(): BelongsTo
    {
        return $this->belongsTo(CargoSalud::class);
    }

    /** "Enfermero/a" o "Enfermero/a, registro 1234": para "Cargado por". */
    public function descripcionCargo(): string
    {
        return $this->cargoSalud->nombre.(filled($this->registro_profesional) ? ', registro '.$this->registro_profesional : '');
    }
}
