<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Persona extends Model
{
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
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    protected function nombreCompleto(): Attribute
    {
        return Attribute::get(fn () => $this->tipo_persona === 'FISICA'
            ? "{$this->apellidos}, {$this->nombres}"
            : $this->razon_social);
    }
}
