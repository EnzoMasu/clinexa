<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\HistoriaClinica;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Historias clínicas: listado de pacientes con su historia y la historia de un paciente con sus
 * consultas. Solo lectura (VER); las lecturas quedan en la auditoría (HISTORIA_CLINICA es sensible).
 * Cantidad y última consulta cuentan solo las FINALIZADAS; las ANULADAS no se muestran en ningún lado.
 */
class HistoriaClinicaController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        $historias = HistoriaClinica::query()
            ->select('historias_clinicas.*')
            ->join('pacientes', 'pacientes.id', '=', 'historias_clinicas.paciente_id')
            ->join('personas', 'personas.id', '=', 'pacientes.persona_id')
            ->with(['paciente.persona.tipoDocumento', 'paciente.estado'])
            ->withCount(['consultas' => fn ($query) => $query->finalizadas()])
            ->withMax(['consultas' => fn ($query) => $query->finalizadas()], 'iniciada_en')
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('personas.apellidos', "%{$busqueda}%")
                ->orWhereLike('personas.nombres', "%{$busqueda}%")
                ->orWhereLike('personas.nro_documento', "{$busqueda}%")
                ->orWhereLike('pacientes.nro_ficha', "%{$busqueda}%")))
            ->orderBy('personas.apellidos')
            ->orderBy('personas.nombres')
            ->orderBy('historias_clinicas.id')
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.historias-clinicas', [
            ...compact('historias', 'busqueda'),
            // Una vez por pedido (no por fila): la parte de la regla que depende solo del usuario.
            'puedeAtender' => Gate::allows('atender', Consulta::class),
        ]);
    }

    public function show(HistoriaClinica $historiaClinica): View
    {
        $historiaClinica->load(['paciente.persona.tipoDocumento', 'paciente.estado']);

        // Solo lo de cada fila: el contenido completo se pide de a una consulta al abrirla (popup o página).
        $consultas = $historiaClinica->consultas()
            ->select(['id', 'historia_clinica_id', 'turno_id', 'profesional_id', 'estado_id', 'fecha_hora', 'iniciada_en', 'motivo_consulta'])
            ->visibles()
            ->with([
                'estado',
                'profesional.persona',
                // Solo los códigos de los diagnósticos vigentes, el principal primero.
                'diagnosticos' => fn ($query) => $query->select(['id', 'consulta_id', 'codigo_cie10', 'principal'])
                    ->where('activo', true)->orderByDesc('principal')->orderBy('id'),
            ])
            // Por la hora en que empezó la atención (las viejas, sin ese dato, por su fecha y hora).
            ->orderByRaw('COALESCE(iniciada_en, fecha_hora) DESC')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.historias-clinicas.show', [
            'historia' => $historiaClinica,
            'consultas' => $consultas,
            'puedeAtender' => Gate::allows('atenderSinTurno', [Consulta::class, $historiaClinica]),
        ]);
    }
}
