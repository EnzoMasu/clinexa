<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\PersonaRequest;
use App\Models\Paciente;
use App\Support\BuscadorPersonas;
use App\Support\Grilla\Grilla;
use Illuminate\Database\Eloquent\Model;
use App\Support\Personas\AltaPersona;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Alta rápida de persona Y paciente juntos (ventana emergente de los selectores de pacientes de Turnos,
     * Urgencias y Atención): mismas validaciones y alta que Personas, más la ficha con el siguiente número
     * libre (sin cobertura: el seguro se carga después en su página). Todo en una transacción y auditado
     * (Persona y Paciente). Responde { id, texto } para elegirlo: el id del paciente o, con ?como=historia
     * (Atención), el de su historia clínica, como los buscadores de cada pantalla.
     */
    public function altaRapidaPaciente(PersonaRequest $request): JsonResponse
    {
        $this->exigirTipoDelRol($request->validated('tipo_persona'));

        $paciente = null;
        for ($intento = 1; $paciente === null; $intento++) {
            try {
                $paciente = DB::transaction(fn () => Paciente::create([
                    'persona_id' => AltaPersona::crear($request->validated())->id,
                    'nro_ficha' => Paciente::siguienteNroFicha(),
                ]));
            } catch (UniqueConstraintViolationException $e) {
                // Dos altas a la vez eligieron el mismo número de ficha: se reintenta con el siguiente.
                // Cualquier otro choque único (p. ej. el documento) es un error de verdad.
                if ($intento >= 3 || ! str_contains($e->getMessage(), 'nro_ficha')) {
                    throw $e;
                }
            }
        }

        $paciente->load('persona.tipoDocumento', 'historiaClinica');

        return response()->json([
            'id' => $request->query('como') === 'historia' ? $paciente->historiaClinica->id : $paciente->id,
            'texto' => BuscadorPersonas::textoRol($paciente, fn (Paciente $p) => "Ficha {$p->nro_ficha}"),
        ], 201, [], BuscadorPersonas::JSON_SEGURO);
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
    protected function grilla(Request $request, string $tabla): Grilla
    {
        return Grilla::de($request)
            ->texto('ficha', 'Nro. ficha', "{$tabla}.nro_ficha")
            ->pipe(fn (Grilla $grilla) => $this->columnasDePersona($grilla))
            ->fecha('alta', 'Fecha de alta', "{$tabla}.fecha_alta")
            ->estado('estado', 'Estado', "{$tabla}.estado_id", Paciente::class);
    }

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
