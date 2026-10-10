<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TieneEstado;
use Database\Factories\PersonaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Persona extends Model
{
    /** @use HasFactory<PersonaFactory> */
    use Auditable, HasFactory, TieneEstado;

    public const CAMPOS_FISICA = ['apellidos', 'nombres', 'fecha_nacimiento', 'sexo', 'pais_nacionalidad_id', 'estado_civil'];

    public const CAMPOS_JURIDICA = ['razon_social', 'nombre_fantasia', 'representante_legal'];

    public const SEXOS = ['M' => 'Masculino', 'F' => 'Femenino', 'OTRO' => 'Otro'];

    public const ESTADOS_CIVILES = ['SOLTERO', 'CASADO', 'VIUDO', 'UNIDO', 'SEPARADO', 'DIVORCIADO'];

    protected $table = 'personas';

    protected $fillable = [
        'tipo_persona',
        'tipo_documento_id',
        'nro_documento',
        'apellidos',
        'nombres',
        'fecha_nacimiento',
        'sexo',
        'pais_nacionalidad_id',
        'estado_civil',
        'razon_social',
        'nombre_fantasia',
        'representante_legal',
        'email',
        'telefono',
        'direccion',
        'estado_id',
        'ciudad_id',
    ];

    public static function moduloEstado(): string
    {
        return 'PERSONAS';
    }

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Los campos que no corresponden al tipo de persona siempre quedan en null,
        // también cuando una persona cambia de FISICA a JURIDICA o viceversa.
        static::saving(function (Persona $persona) {
            $ajenos = $persona->tipo_persona === 'FISICA' ? self::CAMPOS_JURIDICA : self::CAMPOS_FISICA;

            foreach ($ajenos as $campo) {
                $persona->{$campo} = null;
            }
        });

        // Tampoco se desactiva la Persona de una paciente con consultas cerradas (la regla es de Paciente).
        static::updating(function (Persona $persona) {
            if ($persona->isDirty('estado_id') && (int) $persona->estado_id === Estado::idDe(Estado::INACTIVO)
                && $persona->paciente?->tieneConsultasCerradas()) {
                throw new \App\Exceptions\PacienteConConsultasCerradas;
            }
        });

        // users.email es una copia del email de la persona (el login de Laravel lo necesita en users):
        // si cambia acá, se actualiza el del usuario asociado.
        static::saved(function (Persona $persona) {
            if ($persona->wasChanged('email')) {
                $persona->usuario?->update(['email' => self::emailDeUsuario($persona->email)]);
            }
        });
    }

    /**
     * Email tal como se guarda en users: en minúsculas, porque el login lo compara textualmente.
     */
    public static function emailDeUsuario(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Edad en años cumplidos a hoy (hora de Paraguay); null sin fecha de nacimiento. */
    public function edad(): ?int
    {
        return $this->fecha_nacimiento ? (int) $this->fecha_nacimiento->diffInYears(\App\Support\Fecha::hoy()) : null;
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    /**
     * Usuario del sistema de esta persona, si tiene (como máximo uno: users.persona_id es único).
     */
    public function usuario(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * Personas que pueden recibir un usuario nuevo: físicas, activas y sin usuario todavía.
     */
    public function scopeDisponiblesParaUsuario(Builder $query): void
    {
        $query->where('tipo_persona', 'FISICA')
            ->activos()
            ->whereDoesntHave('usuario');
    }

    /** "Rosa Benítez" (nombres y apellidos, en ese orden), para frases; razón social si es jurídica. */
    protected function nombreYApellido(): Attribute
    {
        return Attribute::get(fn () => $this->tipo_persona === 'FISICA'
            ? trim("{$this->nombres} {$this->apellidos}")
            : $this->razon_social);
    }

    protected function nombreCompleto(): Attribute
    {
        return Attribute::get(fn () => $this->tipo_persona === 'FISICA'
            ? "{$this->apellidos}, {$this->nombres}"
            : $this->razon_social);
    }

    /*
     * Roles de negocio de la persona (cada uno como máximo una vez; puede tener varios distintos).
     */
    public function paciente(): HasOne
    {
        return $this->hasOne(Paciente::class);
    }

    public function profesional(): HasOne
    {
        return $this->hasOne(Profesional::class);
    }

    public function proveedor(): HasOne
    {
        return $this->hasOne(Proveedor::class);
    }

    public function responsablePago(): HasOne
    {
        return $this->hasOne(ResponsablePago::class);
    }

    /**
     * País de nacionalidad (opcional, solo personas físicas). Independiente de la ciudad: es de
     * dónde es la persona, no dónde vive.
     */
    public function paisNacionalidad(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais_nacionalidad_id');
    }

    /**
     * Ciudad (opcional): dato adicional a la dirección en texto libre (dónde vive).
     */
    public function ciudad(): BelongsTo
    {
        return $this->belongsTo(Ciudad::class);
    }
}
