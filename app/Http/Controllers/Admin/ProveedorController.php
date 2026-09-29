<?php

namespace App\Http\Controllers\Admin;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Model;

class ProveedorController extends RolPersonaController
{
    protected function modelo(): string
    {
        return Proveedor::class;
    }

    protected function seccion(): string
    {
        return 'proveedores';
    }

    protected function nombre(): string
    {
        return 'Proveedor';
    }

    protected function reglas(?Model $registro): array
    {
        return [
            'condiciones_comerciales' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function atributos(): array
    {
        return ['condiciones_comerciales' => 'condiciones comerciales'];
    }
}
