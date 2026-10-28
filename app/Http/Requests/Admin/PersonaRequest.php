<?php

namespace App\Http\Requests\Admin;

use App\Models\Ciudad;
use App\Models\Pais;
use App\Models\Persona;
use App\Models\TipoDocumento;
use App\Models\User;
use App\Rules\RucValido;
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

        $juridica = $this->input('tipo_persona') === 'JURIDICA';

        $reglas = [
            'tipo_persona' => ['required', Rule::in(['FISICA', 'JURIDICA'])],
            ...$this->reglasDeDocumento($persona),
            'ruc' => [
                $juridica ? 'required' : 'nullable', 'string', 'max:20', new RucValido,
                Rule::unique('personas', 'ruc')->ignore($persona),
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

    /**
     * Persona física: CI o pasaporte, obligatorio. Persona jurídica: no tiene documento (su identificación es el RUC) y no
     * se acepta. Lo ya guardado no se toca: acá solo se valida lo que llega.
     */
    private function reglasDeDocumento(?Persona $persona): array
    {
        if ($this->input('tipo_persona') === 'JURIDICA') {
            return ['tipo_documento_id' => ['prohibited'], 'nro_documento' => ['prohibited']];
        }

        return [
            'tipo_documento_id' => [
                'required',
                'integer',
                // CI o pasaporte (el RUC es un campo aparte), habilitado para Personas (tipo_documento_modulo) y ACTIVO,
                // salvo que sea el que la persona ya tenía asignado.
                function (string $atributo, mixed $valor, \Closure $fail) use ($persona) {
                    $tipo = TipoDocumento::find($valor);
                    if ($tipo && ! in_array($tipo->codigo, Persona::DOCUMENTOS_FISICA, true)) {
                        $fail('Una persona física se identifica con CI o pasaporte. El RUC se carga en su propio campo.');

                        return;
                    }
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
        ];
    }

    public function messages(): array
    {
        return [
            ...Fecha::mensajes('fecha_nacimiento'),
            'tipo_documento_id.prohibited' => 'Una persona jurídica no tiene CI ni pasaporte: se identifica con su RUC.',
            'nro_documento.prohibited' => 'Una persona jurídica no tiene CI ni pasaporte: se identifica con su RUC.',
            'ruc.required' => 'Una persona jurídica necesita su RUC.',
            'ruc.unique' => 'Ya hay una persona registrada con ese RUC.',
        ];
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
            'ruc' => 'RUC',
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
