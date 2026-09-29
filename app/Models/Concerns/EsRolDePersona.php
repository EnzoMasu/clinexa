<?php

namespace App\Models\Concerns;

use App\Models\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rol de negocio sobre una Persona (Paciente, Profesional, Proveedor, ...): la persona se elige
 * al crear el rol y no cambia después; nombre, documento y contacto se leen de ella.
 *
 * @mixin Model
 */
trait EsRolDePersona
{
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * Tipos de persona que pueden tener este rol. Por defecto físicas y jurídicas;
     * los roles que son siempre de una persona humana lo restringen a FISICA.
     *
     * @return list<string>
     */
    public static function tiposPersona(): array
    {
        return ['FISICA', 'JURIDICA'];
    }

    /**
     * Personas que pueden recibir este rol: activas, de un tipo permitido y que todavía no lo tienen.
     */
    public static function personasDisponibles(): Builder
    {
        return Persona::query()
            ->activos()
            ->whereIn('tipo_persona', static::tiposPersona())
            ->whereNotIn('personas.id', static::query()->withoutEagerLoads()->select('persona_id'));
    }
}
