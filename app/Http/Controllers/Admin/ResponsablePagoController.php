<?php

namespace App\Http\Controllers\Admin;

use App\Models\ResponsablePago;
use Illuminate\Database\Eloquent\Model;

class ResponsablePagoController extends RolPersonaController
{
    protected function modelo(): string
    {
        return ResponsablePago::class;
    }

    protected function seccion(): string
    {
        return 'responsables-pago';
    }

    protected function nombre(): string
    {
        return 'Responsable de pago';
    }

    protected function reglas(?Model $registro): array
    {
        return [
            // decimal(12,2): hasta 9.999.999.999,99
            'limite_credito' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }

    protected function atributos(): array
    {
        return ['limite_credito' => 'límite de crédito'];
    }
}
