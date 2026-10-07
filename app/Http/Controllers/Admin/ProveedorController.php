<?php

namespace App\Http\Controllers\Admin;

use App\Models\CategoriaProveedor;
use App\Models\Estado;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

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

    /**
     * Condiciones comerciales, datos bancarios y categorías (varias): activas, o las que ya tenía
     * asignadas aunque después se hayan desactivado.
     */
    protected function reglas(?Model $registro): array
    {
        $yaAsignadas = $registro ? $registro->categorias->modelKeys() : [];

        return [
            'condiciones_comerciales' => ['nullable', 'string', 'max:5000'],
            'datos_bancarios' => ['nullable', 'string', 'max:255'],
            'con_categorias' => ['nullable'],
            'categorias' => ['array'],
            'categorias.*' => ['integer', 'distinct', Rule::exists('categorias_proveedor', 'id')->where(
                fn ($query) => $query->where('estado_id', Estado::idDe(Estado::ACTIVO))
                    ->when($yaAsignadas, fn ($query) => $query->orWhereIn('id', $yaAsignadas))
            )],
        ];
    }

    protected function relacionesListado(): array
    {
        return ['persona.tipoDocumento', 'categorias'];
    }

    protected function atributos(): array
    {
        return [
            'condiciones_comerciales' => 'condiciones comerciales',
            'datos_bancarios' => 'datos bancarios',
            'categorias.*' => 'categoría',
        ];
    }

    protected function datosFormulario(?Model $registro): array
    {
        $yaAsignadas = $registro ? $registro->categorias->modelKeys() : [];

        return [
            'categorias' => CategoriaProveedor::query()
                ->where(fn ($query) => $query->activos()->orWhereIn('id', $yaAsignadas))
                ->orderBy('nombre')
                ->get(),
        ];
    }

    /**
     * Deja exactamente las categorías tildadas. Sin la marca del formulario (un envío que no trae
     * la lista de categorías) no se tocan.
     */
    protected function guardarRelaciones(Model $registro, array $datos): void
    {
        if (empty($datos['con_categorias'])) {
            return;
        }

        $registro->sincronizarAuditado('categorias', array_map('intval', $datos['categorias'] ?? []));
    }
}
