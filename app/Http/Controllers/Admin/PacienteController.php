<?php

namespace App\Http\Controllers\Admin;

use App\Models\Paciente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class PacienteController extends RolPersonaController
{
    protected function modelo(): string
    {
        return Paciente::class;
    }

    protected function seccion(): string
    {
        return 'pacientes';
    }

    protected function nombre(): string
    {
        return 'Paciente';
    }

    /** El número de ficha se propone automáticamente, pero se puede cambiar. */
    protected function nuevo(): Model
    {
        return new Paciente(['nro_ficha' => Paciente::siguienteNroFicha()]);
    }

    protected function reglas(?Model $registro): array
    {
        return [
            'nro_ficha' => ['required', 'string', 'max:20', Rule::unique('pacientes')->ignore($registro)],
        ];
    }

    /** La historia clínica, para el enlace del listado. */
    protected function relacionesListado(): array
    {
        return [...parent::relacionesListado(), 'historiaClinica'];
    }

    protected function columnasBusqueda(): array
    {
        return ['nro_ficha'];
    }

    protected function atributos(): array
    {
        return ['nro_ficha' => 'número de ficha'];
    }
}
