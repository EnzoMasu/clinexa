<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Turno;
use App\Policies\ConsultaPolicy;
use App\Support\Atencion\AtenderSinTurno;
use App\Support\Atencion\Atender;
use App\Support\Atencion\Autoguardado;
use App\Support\Atencion\CerrarJornada;
use App\Support\Atencion\DatosFormulario;
use App\Support\Atencion\DeshacerAtencion;
use App\Support\Atencion\Finalizar;
use App\Support\Atencion\FormularioConsulta;
use App\Support\Atencion\NoSePresento;
use App\Support\Auditoria;
use App\Support\BuscadorPersonas;
use App\Support\Fecha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Pantalla "Consulta" (la lista del profesional: en consulta, agenda de hoy, por llamar de nuevo,
 * atendidos y ausentes) y la pantalla de atención (una sola, con un panel por sección). Las acciones
 * las hacen los servicios de App\Support\Atencion; acá solo se arman las pantallas y se responde.
 */
class AtencionController extends Controller
{
    public const SOLO_PROFESIONALES = 'Esta pantalla es para profesionales. Para revisar un historial use Historias clínicas.';

    /** Relaciones de la pantalla de atención (además de las de las secciones, DatosFormulario). */
    private const RELACIONES_PANTALLA = ['historiaClinica.paciente.persona.tipoDocumento', 'turno', 'profesional.persona'];

    public function index(Request $request): Response
    {
        $profesional = $request->user()->profesional;
        if (! $profesional?->estaActivo()) {
            return response()->view('admin.atencion.aviso', ['mensaje' => self::SOLO_PROFESIONALES]);
        }

        $hoy = Fecha::hoy();
        $turnos = Turno::query()
            ->with(['estado', 'paciente.persona', 'consulta' => fn ($query) => $query
                ->select(['id', 'turno_id', 'estado_id', 'preparada_en', 'historia_clinica_id', 'profesional_id'])
                ->withExists(['bloquesAnamnesis as con_anamnesis' => fn ($q) => $q->where('activo', true), 'examenFisico as con_examen'])])
            ->where('profesional_id', $profesional->id)
            ->whereDate('fecha', $hoy->format('Y-m-d'))
            ->orderBy('hora_inicio')
            ->get();
        $codigo = fn (Turno $turno) => $turno->estado->codigo;

        // Atendidos hoy: sus consultas finalizadas hoy (día de Paraguay, en UTC).
        $atendidos = Consulta::query()
            ->select(['id', 'historia_clinica_id', 'turno_id', 'profesional_id', 'estado_id', 'iniciada_en', 'fecha_hora', 'finalizada_en'])
            ->with(['historiaClinica.paciente.persona', 'turno:id,hora_inicio', 'diagnosticos' => fn ($q) => $q->select(['id', 'consulta_id', 'codigo_cie10', 'principal'])
                ->where('activo', true)->orderByDesc('principal')->orderBy('id')])
            ->where('profesional_id', $profesional->id)->finalizadas()
            ->where('finalizada_en', '>=', $hoy->copy()->startOfDay()->utc())->where('finalizada_en', '<', $hoy->copy()->addDay()->startOfDay()->utc())
            ->orderByDesc('finalizada_en')
            ->get();

        $datos = [
            'enCurso' => Consulta::query()
                ->select(['id', 'historia_clinica_id', 'turno_id', 'profesional_id', 'estado_id', 'iniciada_en', 'fecha_hora'])
                ->with(['historiaClinica.paciente.persona', 'turno:id,hora_inicio'])
                ->where('profesional_id', $profesional->id)->where('estado_id', Estado::idDe(Estado::EN_CURSO))
                ->orderBy('iniciada_en')->get(),
            'agenda' => $turnos->filter(fn ($turno) => in_array($codigo($turno), [Estado::PENDIENTE, Estado::CONFIRMADO], true))->values(),
            'porLlamar' => $turnos->filter(fn ($turno) => $codigo($turno) === Estado::SALTADO)->values(),
            'ausentes' => $turnos->filter(fn ($turno) => $codigo($turno) === Estado::AUSENTE)->values(),
            'atendidos' => $atendidos,
            'anteriores' => Turno::where('profesional_id', $profesional->id)->whereDate('fecha', '<', $hoy->format('Y-m-d'))
                ->whereIn('estado_id', array_map(fn (string $codigo) => Estado::idDe($codigo), CerrarJornada::POR_CERRAR))->count(),
            'puedeAtender' => Gate::allows('atender', Consulta::class),
            'puedePasarAusente' => $request->user()->tienePermiso('TURNOS', 'EDITAR'),
        ];

        return $this->listado($request, 'admin.atencion', $datos);
    }

    /**
     * Buscador de pacientes para "Atender sin turno": el del alta de turnos (solo ACTIVOS, mínimo 2
     * caracteres, hasta 15), con el id de la historia clínica. Solo para quien puede atender.
     */
    public function pacientes(Request $request): JsonResponse
    {
        Gate::authorize('atender', Consulta::class);

        return BuscadorPersonas::responderRol(Paciente::query()->with('historiaClinica'), (string) $request->query('q'), ['nro_ficha'],
            fn (Paciente $paciente) => "Ficha {$paciente->nro_ficha}",
            fn (Paciente $paciente) => $paciente->historiaClinica->id);
    }

    public function atender(Request $request, Turno $turno): RedirectResponse
    {
        return $this->accion(fn () => redirect()->route('admin.consultas.atencion', Atender::ejecutar($request->user(), $turno)));
    }

    public function atenderSinTurno(Request $request, HistoriaClinica $historiaClinica): RedirectResponse
    {
        return $this->accion(fn () => redirect()->route('admin.consultas.atencion', AtenderSinTurno::ejecutar($request->user(), $historiaClinica)));
    }

    public function noSePresento(Request $request, Turno $turno): RedirectResponse
    {
        return $this->accion(function () use ($request, $turno) {
            NoSePresento::ejecutar($request->user(), $turno);

            return redirect()->route('admin.atencion.index')->with('status', 'Turno de las '.substr($turno->hora_inicio, 0, 5).': no se presentó. Quedó en "Por llamar de nuevo".');
        });
    }

    /** Vista previa de "Cerrar jornada": los turnos de hoy y de días anteriores que pasarían a AUSENTE. */
    public function vistaCerrarJornada(Request $request): View
    {
        Gate::authorize('cerrarJornada', Consulta::class);
        $profesional = $request->user()->profesional;

        // Los que todavía no llegaron a su hora no se cierran: se muestran aparte.
        [$turnos, $aunNoEsSuHora] = CerrarJornada::turnosSinCerrar($profesional->id)->partition(fn (Turno $turno) => $turno->llegoSuHora());

        return view('admin.atencion.cerrar-jornada', [
            'turnos' => $turnos->values(),
            'aunNoEsSuHora' => $aunNoEsSuHora->values(),
            'conConsultasEnCurso' => Consulta::where('profesional_id', $profesional->id)->where('estado_id', Estado::idDe(Estado::EN_CURSO))->exists(),
        ]);
    }

    public function cerrarJornada(Request $request): RedirectResponse
    {
        return $this->accion(function () use ($request) {
            [$cerrados, $pendientes] = CerrarJornada::ejecutar($request->user());

            return redirect()->route('admin.atencion.index')->with('status', $cerrados === 0
                ? 'No había turnos para cerrar.'
                : "Jornada cerrada: {$cerrados} ".($cerrados === 1 ? 'turno pasó' : 'turnos pasaron').' a ausente.')
                ->with('aviso', $pendientes === 0 ? null
                    : ($pendientes === 1 ? '1 turno todavía no llegó a su hora y quedó pendiente. ' : "{$pendientes} turnos todavía no llegaron a su hora y quedaron pendientes. ").CerrarJornada::AUN_NO_ES_SU_HORA);
        });
    }

    /**
     * La pantalla de atención de una consulta EN_CURSO. En preparación va al formulario de preparación;
     * finalizada, a la lectura; anulada no existe (404). Además del VER de la consulta (middleware de
     * permisos), registra un VER de la historia: el panel lateral muestra sus otras consultas.
     */
    public function pantalla(Request $request, Consulta $consulta): View|RedirectResponse
    {
        abort_if($consulta->anulada(), 404);
        if ($consulta->enPreparacion()) {
            return redirect()->route('admin.preparacion.formulario', $consulta);
        }
        if ($consulta->finalizada()) {
            return redirect()->route('admin.consultas.show', $consulta);
        }
        Gate::authorize('escribirClinico', $consulta);

        return $this->vistaAtencion($request, $consulta);
    }


    /** Autoguardado (PATCH, JSON). */
    public function autoguardar(Request $request, Consulta $consulta): JsonResponse
    {
        abort_if($consulta->anulada(), 404);

        return response()->json(Autoguardado::ejecutar($request->user(), $consulta, $request));
    }

    public function finalizar(Request $request, Consulta $consulta): RedirectResponse
    {
        // Cerrada (FINALIZADA o ANULADA): 409 antes de llegar acá (middleware consulta.abierta). En preparación
        // (Deshacer en otra pestaña): conflicto, no se finaliza. Solo se lo dice al profesional de la consulta;
        // a otro, 403 como siempre.
        if (! $consulta->enCurso()) {
            abort_unless(app(ConsultaPolicy::class)->esElQueAtiende($request->user(), $consulta), 403, ConsultaPolicy::SOLO_EL_QUE_ATIENDE);
            abort(409, Finalizar::NO_EN_CURSO);
        }
        Gate::authorize('finalizar', $consulta);
        [$datos] = FormularioConsulta::validar($request, $consulta, [FormularioConsulta::PREPARACION, FormularioConsulta::CLINICO], final: true);

        return $this->accion(function () use ($request, $consulta, $datos) {
            $advertencias = Finalizar::ejecutar($request->user(), $consulta, $datos, (string) $request->input('version'));

            return redirect()->route('admin.consultas.show', $consulta)->with('status', 'Consulta finalizada.')
                ->with('aviso', $advertencias === [] ? null : implode(' ', $advertencias));
        }, conDatos: true);
    }


    public function deshacer(Request $request, Consulta $consulta): RedirectResponse
    {
        return $this->accion(function () use ($request, $consulta) {
            $resultado = DeshacerAtencion::ejecutar($request->user(), $consulta);

            return redirect()->route('admin.atencion.index')->with('status', $resultado === 'anulada'
                ? 'Atención sin turno deshecha.'
                : 'Atención deshecha: el paciente volvió a la agenda, con lo cargado en la preparación.');
        });
    }

    private function vistaAtencion(Request $request, Consulta $consulta): View
    {
        $verRecetas = $request->user()->tienePermiso('RECETAS', 'VER');
        $consulta->load([...self::RELACIONES_PANTALLA, ...DatosFormulario::RELACIONES, ...($verRecetas ? ['recetas.detalles'] : [])]);
        if ($verRecetas) {
            $consulta->recetas->each->setRelation('consulta', $consulta);
        }

        // Historial del paciente (panel lateral): sus consultas FINALIZADAS anteriores, solo lo de la fila.
        $historial = $consulta->historiaClinica->consultas()
            ->select(['id', 'historia_clinica_id', 'profesional_id', 'estado_id', 'fecha_hora', 'iniciada_en', 'motivo_consulta'])
            ->with(['profesional.persona', 'diagnosticos' => fn ($q) => $q->select(['id', 'consulta_id', 'codigo_cie10', 'principal'])->where('activo', true)->orderByDesc('principal')->orderBy('id')])
            ->finalizadas()->whereKeyNot($consulta->id)
            ->orderByRaw('COALESCE(iniciada_en, fecha_hora) DESC')->orderByDesc('id')
            ->limit(20)->get();

        if (! $request->ajax()) {
            Auditoria::registrarLectura('HISTORIA_CLINICA', $consulta->historia_clinica_id, 'historias_clinicas');
        }

        $errores = session('errors')?->getBag('default');
        $seccionesConError = collect(['anamnesis' => 'anamnesis*', 'examen' => 'examen*', 'motivo' => ['motivo_consulta', 'diagnostico*'], 'indicaciones' => 'indicaciones*'])
            ->filter(fn ($claves) => collect((array) $claves)->contains(fn ($clave) => $errores?->has($clave)))->keys()->all();
        $panelInicial = $seccionesConError[0] ?? ($consulta->bloquesAnamnesis->where('activo', true)->isEmpty() ? 'anamnesis' : 'motivo');

        return view('admin.atencion.pantalla', [
            'consulta' => $consulta,
            'datos' => DatosFormulario::de($consulta),
            'historial' => $historial,
            'verRecetas' => $verRecetas,
            'puedeCrearReceta' => $verRecetas && Gate::allows('create', [\App\Models\Receta::class, $consulta]),
            'seccionesConError' => $seccionesConError,
            'panelInicial' => $panelInicial,
        ]);
    }

    /** Corre una acción del flujo; si se rechaza (AccionRechazada), vuelve con el aviso sin guardar nada. */
    private function accion(\Closure $accion, bool $conDatos = false): RedirectResponse
    {
        try {
            return $accion();
        } catch (AccionRechazada $e) {
            $volver = back()->with('error', $e->getMessage());

            return $conDatos ? $volver->withInput() : $volver;
        }
    }
}
