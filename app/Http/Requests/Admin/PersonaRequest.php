<?php

namespace App\Http\Requests\Admin;

use App\Models\Persona;
use App\Models\User;
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
        $tipoPersona = $this->input('tipo_persona');

        $reglas = [
            'tipo_persona' => ['required', Rule::in(['FISICA', 'JURIDICA'])],
            'tipo_documento_id' => [
                'required',
                'integer',
                // El tipo de documento tiene que aplicar al tipo de persona y estar ACTIVO
                // (salvo que sea el que la persona ya tenía asignado).
                Rule::exists('tipos_documento', 'id')->where(fn ($query) => $query
                    ->whereIn('aplica_a', [$tipoPersona, 'AMBOS'])
                    ->where(fn ($query) => $query
                        ->where('estado', 'ACTIVO')
                        ->orWhere('id', $persona?->tipo_documento_id))),
            ],
            'nro_documento' => [
                'required',
                'string',
                'max:20',
                Rule::unique('personas')->where('tipo_documento_id', $this->input('tipo_documento_id'))->ignore($persona),
            ],

            'apellidos' => ['exclude_unless:tipo_persona,FISICA', 'required', 'string', 'max:100'],
            'nombres' => ['exclude_unless:tipo_persona,FISICA', 'required', 'string', 'max:100'],
            'fecha_nacimiento' => ['exclude_unless:tipo_persona,FISICA', 'required', 'date', 'before_or_equal:today'],
            'sexo' => ['exclude_unless:tipo_persona,FISICA', 'nullable', Rule::in(array_keys(Persona::SEXOS))],
            'nacionalidad' => ['exclude_unless:tipo_persona,FISICA', 'nullable', 'string', 'max:50'],
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
        ];

        if ($persona) {
            $reglas['estado'] = ['required', Rule::in(['ACTIVO', 'INACTIVO'])];
        }

        return $reglas;
    }

    public function attributes(): array
    {
        return [
            'tipo_persona' => 'tipo de persona',
            'tipo_documento_id' => 'tipo de documento',
            'nro_documento' => 'número de documento',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'estado_civil' => 'estado civil',
            'razon_social' => 'razón social',
            'nombre_fantasia' => 'nombre de fantasía',
            'representante_legal' => 'representante legal',
        ];
    }
}
