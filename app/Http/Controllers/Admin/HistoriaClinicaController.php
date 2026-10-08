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
            ->withCount('consultas')
            ->withMax('consultas', 'fecha_hora')
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

        return $this->listado($request, 'admin.historias-clinicas', compact('historias', 'busqueda'));
    }

    public function show(HistoriaClinica $historiaClinica): View
    {
        $historiaClinica->load(['paciente.persona.tipoDocumento', 'paciente.estado']);

        $consultas = $historiaClinica->consultas()
            ->with([
                'profesional.persona',
                // Solo los diagnósticos vigentes, el principal primero.
                'diagnosticos' => fn ($query) => $query->where('activo', true)->orderByDesc('principal')->orderBy('id'),
            ])
            ->orderByDesc('fecha_hora')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.historias-clinicas.show', [
            'historia' => $historiaClinica,
            'consultas' => $consultas,
            'puedeAtender' => Gate::allows('create', [Consulta::class, $historiaClinica]),
        ]);
    }
}
