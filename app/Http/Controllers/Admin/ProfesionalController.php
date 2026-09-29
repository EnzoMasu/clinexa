<?php

namespace App\Http\Controllers\Admin;

use App\Models\Especialidad;
use App\Models\Estado;
use App\Models\Profesional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ProfesionalController extends RolPersonaController
{
    protected function modelo(): string
    {
        return Profesional::class;
    }

    protected function seccion(): string
    {
        return 'profesionales';
    }

    protected function nombre(): string
    {
        return 'Profesional';
    }

    /**
     * Matrícula y especialidades: cada especialidad una sola vez, activa (o una que ya tenía
     * asignada aunque después se haya desactivado), con su matrícula opcional y fecha desde.
     */
    protected function reglas(?Model $registro): array
    {
        $yaAsignadas = $registro ? $registro->especialidades->modelKeys() : [];

        return [
            'matricula' => ['required', 'string', 'max:50', Rule::unique('profesionales')->ignore($registro)],
            'con_especialidades' => ['nullable'],
            'especialidades' => ['array'],
            'especialidades.*.especialidad_id' => ['required', 'integer', 'distinct', Rule::exists('especialidades', 'id')->where(
                fn ($query) => $query->where('estado_id', Estado::idDe(Estado::ACTIVO))
                    ->when($yaAsignadas, fn ($query) => $query->orWhereIn('id', $yaAsignadas))
            )],
            'especialidades.*.nro_matricula_especialidad' => ['nullable', 'string', 'max:50'],
            'especialidades.*.fecha_desde' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    protected function relacionesListado(): array
    {
        return ['persona.tipoDocumento', 'especialidades'];
    }

    protected function columnasBusqueda(): array
    {
        return ['matricula'];
    }

    protected function atributos(): array
    {
        return [
            'matricula' => 'matrícula',
            'especialidades.*.especialidad_id' => 'especialidad',
            'especialidades.*.nro_matricula_especialidad' => 'matrícula de la especialidad',
            'especialidades.*.fecha_desde' => 'fecha desde',
        ];
    }

    protected function datosFormulario(?Model $registro): array
    {
        $yaAsignadas = $registro ? $registro->especialidades->modelKeys() : [];

        return [
            'especialidades' => Especialidad::query()
                ->where(fn ($query) => $query->activos()->orWhereIn('id', $yaAsignadas))
                ->orderBy('nombre')
                ->pluck('nombre', 'id'),
        ];
    }

    /**
     * Deja exactamente las especialidades del formulario (con sus datos en el pivote).
     */
    protected function guardarRelaciones(Model $registro, array $datos): void
    {
        // Sin la marca del formulario (sin JavaScript no se dibujan las filas) no se tocan las especialidades.
        if (empty($datos['con_especialidades'])) {
            return;
        }

        $registro->especialidades()->sync(collect($datos['especialidades'] ?? [])->mapWithKeys(fn (array $fila) => [
            $fila['especialidad_id'] => [
                'nro_matricula_especialidad' => $fila['nro_matricula_especialidad'] ?? null,
                'fecha_desde' => $fila['fecha_desde'],
            ],
        ])->all());
    }

    protected function registro(string $id): Model
    {
        return Profesional::with(['persona.tipoDocumento', 'especialidades'])->findOrFail($id);
    }
}
