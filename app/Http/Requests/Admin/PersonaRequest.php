<?php

namespace App\Http\Requests\Admin;

use App\Models\Ciudad;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Support\Fecha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Los campos del otro tipo de persona se excluyen (exclude_unless) y el modelo
     * los guarda en null, así que no hace falta que el formulario los mande vacíos.
     */
    public function rules(): array
    {
        /** @var Persona|null $persona */
        $persona = $this->route('persona');

        $reglas = [
            'tipo_persona' => ['required', Rule::in(['FISICA', 'JURIDICA'])],
            'tipo_documento_id' => [
                'required',
                'integer',
                // Tiene que estar habilitado para Personas (tipo_documento_modulo) y ACTIVO,
                // salvo que sea el que la persona ya tenía asignado.
                function (string $atributo, mixed $valor, \Closure $fail) use ($persona) {
                    if ($persona && (int) $valor === $persona->tipo_documento_id) {
                        return;
                    }
                    if (! TipoDocumento::query()->habilitadosPara('PERSONAS')->activos()->where('tipos_documento.id', $valor)->exists()) {
                        $fail('El tipo de documento no está habilitado para personas o está inactivo.');
                    }
                },
            ],
            'nro_documento' => [
                'required',
                'string',
                'max:20',
                Rule::unique('personas')->where('tipo_documento_id', $this->input('tipo_documento_id'))->ignore($persona),
            ],

            'apellidos' => ['exclude_unless:tipo_persona,FISICA', 'required', 'string', 'max:100'],
            'nombres' => ['exclude_unless:tipo_persona,FISICA', 'required', 'string', 'max:100'],
            'fecha_nacimiento' => ['exclude_unless:tipo_persona,FISICA', 'required', ...Fecha::regla(hastaHoy: true)],
            'sexo' => ['exclude_unless:tipo_persona,FISICA', 'nullable', Rule::in(array_keys(Persona::SEXOS))],
            'pais_nacionalidad_id' => ['exclude_unless:tipo_persona,FISICA', ...Pais::reglaOpcional($persona?->pais_nacionalidad_id)],
            'estado_civil' => ['exclude_unless:tipo_persona,FISICA', 'nullable', Rule::in(Persona::ESTADOS_CIVILES)],

            'razon_social' => ['exclude_unless:tipo_persona,JURIDICA', 'required', 'string', 'max:150'],
            'nombre_fantasia' => ['exclude_unless:tipo_persona,JURIDICA', 'nullable', 'string', 'max:150'],
            'representante_legal' => ['exclude_unless:tipo_persona,JURIDICA', 'nullable', 'string', 'max:150'],

            'email' => ['required', 'email', 'max:100', function (string $atributo, mixed $valor, \Closure $fail) use ($persona) {
                // El email de una persona con usuario se copia a users.email, que es único.
                $usuario = $persona?->usuario;
                if ($usuario && User::where('email', Persona::emailDeUsuario((string) $valor))->whereKeyNot($usuario->id)->exists()) {
                    $fail('Ese email ya lo usa otro usuario del sistema.');
                }
            }],
            'telefono' => ['required', 'string', 'max:20'],
            'direccion' => ['required', 'string', 'max:200'],
            'ciudad_id' => Ciudad::reglaOpcional($persona?->ciudad_id),
        ];

        if ($persona) {
            $reglas['estado_id'] = Persona::reglaEstado();
        }

        return $reglas;
    }

    public function messages(): array
    {
        return Fecha::mensajes('fecha_nacimiento');
    }

    /**
     * La fecha de nacimiento llega como dd/mm/aaaa y se guarda en ISO.
     */
    public function validated($key = null, $default = null)
    {
        $datos = parent::validated();
        if (array_key_exists('fecha_nacimiento', $datos)) {
            $datos['fecha_nacimiento'] = Fecha::aIso($datos['fecha_nacimiento']);
        }

        return data_get($datos, $key, $default);
    }

    public function attributes(): array
    {
        return [
            'tipo_persona' => 'tipo de persona',
            'tipo_documento_id' => 'tipo de documento',
            'nro_documento' => 'número de documento',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'estado_civil' => 'estado civil',
            'ciudad_id' => 'ciudad',
            'pais_nacionalidad_id' => 'nacionalidad',
            'razon_social' => 'razón social',
            'nombre_fantasia' => 'nombre de fantasía',
            'representante_legal' => 'representante legal',
        ];
    }
}
