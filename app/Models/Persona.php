<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Persona extends Model
{
    /** @use HasFactory<\Database\Factories\PersonaFactory> */
    use HasFactory;

    public const CAMPOS_FISICA = ['apellidos', 'nombres', 'fecha_nacimiento', 'sexo', 'nacionalidad', 'estado_civil'];

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
        'nacionalidad',
        'estado_civil',
        'razon_social',
        'nombre_fantasia',
        'representante_legal',
        'email',
        'telefono',
        'direccion',
        'estado',
    ];

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
            ->where('estado', 'ACTIVO')
            ->whereDoesntHave('usuario');
    }

    protected function nombreCompleto(): Attribute
    {
        return Attribute::get(fn () => $this->tipo_persona === 'FISICA'
            ? "{$this->apellidos}, {$this->nombres}"
            : $this->razon_social);
    }
}
