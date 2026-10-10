<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Profesional;
use App\Models\Turno;
use App\Support\Atencion\DatosFormulario;
use App\Support\Atencion\MarcarLista;
use App\Support\Atencion\Preparar;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Preparación: la lista de turnos de hoy de todos los profesionales por preparar (solo datos de agenda,
 * sin contenido clínico) y el formulario de preparación (anamnesis y signos vitales) de una consulta.
 */
class PreparacionController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $profesionalId = ctype_digit((string) $request->query('profesional')) ? (int) $request->query('profesional') : null;
        $estados = array_map(fn (string $codigo) => Estado::idDe($codigo), [Estado::PENDIENTE, Estado::CONFIRMADO, Estado::SALTADO, Estado::EN_CONSULTA]);

        $turnos = Turno::query()
            ->select('turnos.*')
            ->join('pacientes', 'pacientes.id', '=', 'turnos.paciente_id')
            ->join('personas as persona_paciente', 'persona_paciente.id', '=', 'pacientes.persona_id')
            ->join('profesionales', 'profesionales.id', '=', 'turnos.profesional_id')
            ->join('personas as persona_profesional', 'persona_profesional.id', '=', 'profesionales.persona_id')
            ->with(['estado', 'paciente.persona', 'profesional.persona', 'consulta' => fn ($query) => $query
                ->select(['id', 'turno_id', 'estado_id', 'preparada_en', 'historia_clinica_id', 'profesional_id'])
                ->withExists(['bloquesAnamnesis as con_anamnesis' => fn ($q) => $q->where('activo', true), 'examenFisico as con_examen'])])
            ->whereDate('turnos.fecha', Fecha::hoy()->format('Y-m-d'))
            ->whereIn('turnos.estado_id', $estados)
            ->when($profesionalId, fn ($query) => $query->where('turnos.profesional_id', $profesionalId))
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('persona_paciente.apellidos', "%{$busqueda}%")
                ->orWhereLike('persona_paciente.nombres', "%{$busqueda}%")
                ->orWhereLike('pacientes.nro_ficha', "%{$busqueda}%")
                ->orWhereLike('persona_profesional.apellidos', "%{$busqueda}%")
                ->orWhereLike('persona_profesional.nombres', "%{$busqueda}%")))
            ->orderBy('turnos.hora_inicio')->orderBy('turnos.id')
            ->get();

        return $this->listado($request, 'admin.preparacion', [
            'turnos' => $turnos,
            'busqueda' => $busqueda,
            'profesional' => $profesionalId,
            'puedeCrear' => $request->user()->tienePermiso('PREPARACION', 'CREAR'),
            'puedeEditar' => $request->user()->tienePermiso('PREPARACION', 'EDITAR'),
            ...($request->ajax() ? [] : ['profesionales' => Profesional::activos()->with('persona')->get()->sortBy(fn ($p) => $p->persona->nombre_completo)
                ->mapWithKeys(fn ($p) => [$p->id => $p->persona->nombre_completo])->all()]),
        ]);
    }

    /** Preparar (POST por turno): crea o retoma la consulta EN_PREPARACION y va a su formulario. */
    public function preparar(Request $request, Turno $turno): RedirectResponse
    {
        try {
            return redirect()->route('admin.preparacion.formulario', Preparar::ejecutar($request->user(), $turno));
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * El formulario de preparación. Quien no escribe la preparación no entra. Abrirlo (carga normal) es
     * leer contenido clínico: registra VER sobre la consulta, aunque la ruta sea de la preparación.
     */
    public function formulario(Request $request, Consulta $consulta): View|RedirectResponse
    {
        abort_if($consulta->anulada(), 404);
        if ($consulta->finalizada()) {
            return redirect()->route('admin.consultas.show', $consulta);
        }
        Gate::authorize('escribirPreparacion', $consulta);
        $consulta->load(['historiaClinica.paciente.persona.tipoDocumento', 'turno', 'profesional.persona', ...DatosFormulario::RELACIONES]);

        if (! $request->ajax()) {
            Auditoria::registrarLectura('HISTORIA_CLINICA', $consulta->id, 'consultas');
        }

        return view('admin.preparacion.form', [
            'consulta' => $consulta,
            'datos' => DatosFormulario::de($consulta),
            'puedeMarcar' => Gate::allows('marcarLista', $consulta),
        ]);
    }

    public function marcarLista(Request $request, Consulta $consulta): RedirectResponse
    {
        return $this->marcar($request, $consulta, true);
    }

    public function reabrir(Request $request, Consulta $consulta): RedirectResponse
    {
        return $this->marcar($request, $consulta, false);
    }

    private function marcar(Request $request, Consulta $consulta, bool $lista): RedirectResponse
    {
        abort_if($consulta->anulada(), 404);
        MarcarLista::ejecutar($request->user(), $consulta, $lista);

        return redirect()->route('admin.preparacion.formulario', $consulta)->with('status', $lista ? 'Preparación marcada como lista.' : 'Preparación reabierta.');
    }
}
