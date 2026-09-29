<?php

namespace App\Http\Controllers\Admin;

use App\Models\PropietarioEquipo;
use Illuminate\Database\Eloquent\Model;

class PropietarioEquipoController extends RolPersonaController
{
    protected function modelo(): string
    {
        return PropietarioEquipo::class;
    }

    protected function seccion(): string
    {
        return 'propietarios-equipo';
    }

    protected function nombre(): string
    {
        return 'Propietario de equipo';
    }

    protected function reglas(?Model $registro): array
    {
        return [
            'datos_bancarios' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function atributos(): array
    {
        return ['datos_bancarios' => 'datos bancarios'];
    }
}
