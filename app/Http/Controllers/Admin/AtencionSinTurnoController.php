<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Paciente;
use App\Support\BuscadorPersonas;
use App\Support\Fecha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Atención sin turno (urgencias): entrada directa para iniciar una consulta sin turno y el listado
 * de las atenciones sin turno de un día. La consulta se carga en el mismo formulario y ruta que
 * "Atender sin turno" de la historia (ConsultaController, ConsultaPolicy): acá no se valida nada
 * de la consulta.
 */
class AtencionSinTurnoController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $dia = $this->dia((string) $request->query('fecha'));
        // El día es de Paraguay: de 00:00 a 24:00 locales, que en UTC (como se guarda fecha_hora) son
        // de 03:00 a 03:00 del día siguiente. Una consulta de las 22:30 locales cae en su día local.
        $desde = $dia->copy()->startOfDay()->utc();
        $hasta = $dia->copy()->addDay()->startOfDay()->utc();

        // Solo lo que muestra la fila: nada de anamnesis, examen físico ni diagnósticos.
        $consultas = Consulta::query()
            ->select(['consultas.id', 'consultas.historia_clinica_id', 'consultas.profesional_id', 'consultas.fecha_hora', 'consultas.motivo_consulta'])
            ->whereNull('consultas.turno_id')
            ->where('consultas.fecha_hora', '>=', $desde)
            ->where('consultas.fecha_hora', '<', $hasta)
            ->when($busqueda !== '', fn ($query) => $query
                ->join('historias_clinicas', 'historias_clinicas.id', '=', 'consultas.historia_clinica_id')
                ->join('pacientes', 'pacientes.id', '=', 'historias_clinicas.paciente_id')
                ->join('personas as persona_paciente', 'persona_paciente.id', '=', 'pacientes.persona_id')
                ->join('profesionales', 'profesionales.id', '=', 'consultas.profesional_id')
                ->join('personas as persona_profesional', 'persona_profesional.id', '=', 'profesionales.persona_id')
                ->where(fn ($query) => $query
                    ->whereLike('persona_paciente.apellidos', "%{$busqueda}%")
                    ->orWhereLike('persona_paciente.nombres', "%{$busqueda}%")
                    ->orWhereLike('pacientes.nro_ficha', "%{$busqueda}%")
                    ->orWhereLike('persona_profesional.apellidos', "%{$busqueda}%")
                    ->orWhereLike('persona_profesional.nombres', "%{$busqueda}%")))
            ->with(['historiaClinica.paciente.persona', 'profesional.persona'])
            ->orderByDesc('consultas.fecha_hora')
            ->orderByDesc('consultas.id')
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.atencion-sin-turno', [
            'consultas' => $consultas,
            'busqueda' => $busqueda,
            'fecha' => $dia->format(Fecha::FORMATO),
            'esHoy' => $dia->isSameDay(Fecha::hoy()),
            // Iniciar una atención: la misma condición que "Atender sin turno" de la historia.
            ...($request->ajax() ? [] : ['puedeAtender' => Gate::allows('atender', Consulta::class)]),
        ]);
    }

    /**
     * Buscador de pacientes para iniciar la atención: el del alta de turnos (solo ACTIVOS, mínimo 2
     * caracteres, hasta 15), pero devuelve el id de la historia clínica, que es lo que necesita el
     * formulario de la consulta. Cada resultado trae solo { id de la historia, "Apellido, Nombre — CI
     * 1234567 · Ficha FP-0000001" }.
     *
     * La ruta exige CREAR sobre HISTORIA_CLINICA y, además, la misma regla que la parte de iniciar
     * atención: ser profesional ACTIVO (ConsultaPolicy::atender). Quien no puede atender no busca nombres.
     */
    public function pacientes(Request $request): JsonResponse
    {
        Gate::authorize('atender', Consulta::class);

        return BuscadorPersonas::responderRol(Paciente::query()->with('historiaClinica'), (string) $request->query('q'), ['nro_ficha'],
            fn (Paciente $paciente) => "Ficha {$paciente->nro_ficha}",
            fn (Paciente $paciente) => $paciente->historiaClinica->id);
    }

    /** El día del filtro (dd/mm/aaaa, en hora de Paraguay), o hoy si no viene o está mal escrito. */
    private function dia(string $texto): Carbon
    {
        $texto = trim($texto);
        if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) && Carbon::canBeCreatedFromFormat($texto, Fecha::FORMATO)) {
            return Carbon::createFromFormat('!'.Fecha::FORMATO, $texto, config('app.zona_horaria_local'));
        }

        return Fecha::hoy();
    }
}
