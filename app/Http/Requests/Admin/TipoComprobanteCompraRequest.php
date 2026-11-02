<?php

namespace App\Http\Requests\Admin;

use App\Models\TipoComprobanteCompra;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta y edición de un tipo de comprobante de compra (docs/compras-gastos.md): nombre único y sus casillas de reglas. */
class TipoComprobanteCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Las casillas sin marcar no llegan en el pedido: se toman como falsas. */
    protected function prepareForValidation(): void
    {
        $this->merge(collect(TipoComprobanteCompra::CASILLAS)->keys()->mapWithKeys(fn (string $casilla) => [$casilla => $this->boolean($casilla)])->all());
    }

    public function rules(): array
    {
        /** @var TipoComprobanteCompra|null $tipo */
        $tipo = $this->route('tipoComprobanteCompra');

        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('tipos_comprobante_compra')->ignore($tipo)],
            'requiere_numero' => ['boolean'],
            // El formato legal es una regla del número: sin número no tiene sentido.
            'numero_formato_legal' => ['boolean', Rule::prohibitedIf(fn () => $this->boolean('numero_formato_legal') && ! $this->boolean('requiere_numero'))],
            'requiere_timbrado' => ['boolean'],
            'requiere_fecha_comprobante' => ['boolean'],
            'discrimina_iva' => ['boolean'],
        ];
        if ($tipo) {
            $reglas['estado_id'] = TipoComprobanteCompra::reglaEstado();
        }

        return $reglas;
    }

    public function messages(): array
    {
        return ['numero_formato_legal.prohibited' => 'El formato legal solo se puede exigir si el tipo requiere número.'];
    }

    public function attributes(): array
    {
        return array_map('mb_strtolower', TipoComprobanteCompra::CASILLAS);
    }
}
