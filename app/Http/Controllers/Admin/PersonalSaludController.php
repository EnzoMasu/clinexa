<?php

namespace App\Http\Controllers\Admin;

use App\Models\CargoSalud;
use App\Models\PersonalSalud;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rol "Personal de salud" (enfermería, obstetricia, auxiliares...): mismo patrón que los otros roles de persona.
 * Con el rol activo, la persona puede cargar el triaje (ConsultaPolicy::puedeCargarTriaje), además del permiso.
 */
class PersonalSaludController extends RolPersonaController
{
    protected function modelo(): string
    {
        return PersonalSalud::class;
    }

    protected function seccion(): string
    {
        return 'personal-salud';
    }

    protected function nombre(): string
    {
        return 'Personal de salud';
    }

    protected function relacionesListado(): array
    {
        return ['persona.tipoDocumento', 'cargoSalud'];
    }

    protected function columnasBusqueda(): array
    {
        return ['registro_profesional'];
    }

    protected function prepararPedido(Request $request): void
    {
        $request->merge(['registro_profesional' => filled($registro = trim((string) $request->input('registro_profesional'))) ? $registro : null]);
    }

    protected function reglas(?Model $registro): array
    {
        return [
            // Un cargo activo, o el que ya tenía (aunque se haya desactivado).
            'cargo_salud_id' => ['required', 'integer', Rule::exists('cargos_salud', 'id')->where(fn ($query) => $query
                ->where('estado_id', \App\Models\Estado::idDe(\App\Models\Estado::ACTIVO))
                ->when($registro, fn ($query) => $query->orWhere('id', $registro->cargo_salud_id)))],
            'registro_profesional' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function datosFormulario(?Model $registro): array
    {
        return [
            'cargos' => CargoSalud::query()->where(fn ($query) => $query->activos()->when($registro, fn ($query) => $query->orWhere('id', $registro->cargo_salud_id)))
                ->orderBy('nombre')->pluck('nombre', 'id')->all(),
        ];
    }

    protected function atributos(): array
    {
        return ['cargo_salud_id' => 'cargo', 'registro_profesional' => 'registro profesional'];
    }
}
