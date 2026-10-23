<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Convenio;
use App\Models\Estado;
use App\Models\PlanConvenio;
use App\Models\Procedimiento;
use App\Models\ResponsablePago;
use App\Models\TarifaConvenio;
use App\Support\Cobros\Guaranies;
use App\Support\Convenios\GestionConvenios;
use App\Support\Fecha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Convenios (docs/convenios-seguros.md), un solo módulo del grupo Económico con dos pestañas: "Convenios y planes" (listado, alta con
 * sus planes en un paso, detalle con los planes, agregar, activar o desactivar un plan y pasar a histórico) y "Tarifas del convenio"
 * (listado, un solo formulario para el alta y el cambio, historial y baja). Nada se borra. Permisos sobre CONVENIOS: Administrador,
 * Gerencia y Facturación y convenios.
 */
class ConvenioController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $conHistoricos = $request->query('estado') === 'TODOS';

        return $this->listado($request, 'admin.convenios', [
            'convenios' => Convenio::query()
                ->join('responsables_pago', 'responsables_pago.id', '=', 'convenios.responsable_pago_id')
                ->join('personas', 'personas.id', '=', 'responsables_pago.persona_id')
                ->unless($conHistoricos, fn ($query) => $query->where('convenios.estado_id', Estado::idDe(Estado::VIGENTE)))
                ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereLike('convenios.nombre', "%{$busqueda}%")
                    ->orWhereLike('personas.apellidos', "%{$busqueda}%")
                    ->orWhereLike('personas.nombres', "%{$busqueda}%")))
                ->orderBy('personas.apellidos')->orderBy('personas.nombres')->orderByDesc('convenios.vigente_desde')
                ->select('convenios.*')
                ->with(['responsablePago.persona', 'planes'])
                ->paginate(20)->withQueryString(),
            'busqueda' => $busqueda,
            'filtros' => ['estado' => $conHistoricos ? 'TODOS' : 'VIGENTES'],
            'activa' => 'convenios',
        ]);
    }

    public function create(): View
    {
        return view('admin.convenios.form', ['responsables' => self::responsables()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'responsable_pago_id' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:'.GestionConvenios::NOMBRE_MAXIMO],
            'vigente_desde' => ['required', ...Fecha::regla()],
            'vigente_hasta' => ['nullable', ...Fecha::regla()],
            'planes' => ['nullable', 'string', 'max:5000'],
        ], [...Fecha::mensajes('vigente_desde'), ...Fecha::mensajes('vigente_hasta')],
            ['responsable_pago_id' => 'aseguradora', 'vigente_desde' => 'vigente desde', 'vigente_hasta' => 'vigente hasta']);

        try {
            $convenio = GestionConvenios::alta($request->user(), (int) $datos['responsable_pago_id'], $datos['nombre'],
                Carbon::parse(Fecha::aIso($datos['vigente_desde'])), isset($datos['vigente_hasta']) ? Carbon::parse(Fecha::aIso($datos['vigente_hasta'])) : null,
                preg_split('/\R/', (string) ($datos['planes'] ?? '')) ?: []);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.convenios.show', $convenio)->with('status', "Convenio creado: {$convenio->nombre}.");
    }

    public function show(Convenio $convenio): View
    {
        $convenio->load(['responsablePago.persona', 'planes', 'usuario.persona']);

        return view('admin.convenios.show', [
            'convenio' => $convenio,
            'tarifas' => TarifaConvenio::query()->where('convenio_id', $convenio->id)->where('estado_id', Estado::idDe(Estado::VIGENTE))
                ->with(['procedimiento', 'moneda'])->get()->sortBy('procedimiento.nombre'),
        ]);
    }

    public function agregarPlan(Request $request, Convenio $convenio): RedirectResponse
    {
        $nombre = $request->validate(['nombre' => ['required', 'string', 'max:'.GestionConvenios::PLAN_MAXIMO]], [], ['nombre' => 'nombre del plan'])['nombre'];

        try {
            $plan = GestionConvenios::agregarPlan($convenio, $nombre);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', "Plan agregado: {$plan->nombre}.");
    }

    public function estadoPlan(Request $request, PlanConvenio $plan): RedirectResponse
    {
        $activar = $request->input('accion') === 'activar';
        GestionConvenios::cambiarEstadoPlan($plan, $activar);

        return back()->with('status', $activar ? "Plan activado: {$plan->nombre}." : "Plan desactivado: {$plan->nombre}. Las coberturas ya cargadas no cambian.");
    }

    public function pasarAHistorico(Request $request, Convenio $convenio): RedirectResponse
    {
        $dia = $request->validate(['ultimo_dia' => ['required', ...Fecha::regla()]], Fecha::mensajes('ultimo_dia'), ['ultimo_dia' => 'último día'])['ultimo_dia'];

        try {
            GestionConvenios::pasarAHistorico($convenio, Carbon::parse(Fecha::aIso($dia)));
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Convenio pasado a histórico.');
    }

    /** Pestaña "Tarifas del convenio": las vigentes (o también las históricas), con búsqueda y filtro por convenio. */
    public function tarifas(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $conHistoricas = $request->query('estado') === 'TODAS';
        $convenioId = filter_var($request->query('convenio'), FILTER_VALIDATE_INT) ?: null;

        return $this->listado($request, 'admin.convenios.tarifas', [
            'tarifas' => TarifaConvenio::query()
                ->join('procedimientos', 'procedimientos.id', '=', 'tarifas_convenio.procedimiento_id')
                ->join('convenios', 'convenios.id', '=', 'tarifas_convenio.convenio_id')
                ->unless($conHistoricas, fn ($query) => $query->where('tarifas_convenio.estado_id', Estado::idDe(Estado::VIGENTE)))
                ->when($convenioId, fn ($query) => $query->where('tarifas_convenio.convenio_id', $convenioId))
                ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereLike('procedimientos.nombre', "%{$busqueda}%")->orWhereLike('procedimientos.codigo', "%{$busqueda}%")))
                ->orderBy('convenios.nombre')->orderBy('procedimientos.nombre')->orderByDesc('tarifas_convenio.vigente_desde')->orderByDesc('tarifas_convenio.id')
                ->select('tarifas_convenio.*')
                ->with(['procedimiento', 'convenio.responsablePago.persona', 'moneda'])
                ->paginate(20)->withQueryString(),
            'busqueda' => $busqueda,
            'filtros' => ['estado' => $conHistoricas ? 'TODAS' : 'VIGENTES', 'convenio' => $convenioId],
            'opcionesConvenio' => self::convenios(),
            'activa' => 'tarifas',
        ]);
    }

    /** Un solo formulario para el alta y el cambio (si el procedimiento ya tiene tarifa en el convenio, se cierra y se abre otra). */
    public function crearTarifa(Request $request): View
    {
        return view('admin.convenios.tarifa-form', [
            'convenios' => self::convenios(),
            'procedimientos' => Procedimiento::activos()->orderBy('nombre')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->nombre} ({$p->codigo})"])->all(),
            'convenioId' => filter_var($request->query('convenio'), FILTER_VALIDATE_INT) ?: null,
            'procedimientoId' => filter_var($request->query('procedimiento'), FILTER_VALIDATE_INT) ?: null,
        ]);
    }

    public function guardarTarifa(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'convenio_id' => ['required', 'integer', 'exists:convenios,id'],
            'procedimiento_id' => ['required', 'integer', 'exists:procedimientos,id'],
            'monto' => Guaranies::regla(),
            'vigente_desde' => ['required', ...Fecha::regla()],
        ], ['monto.regex' => Guaranies::MENSAJE, ...Fecha::mensajes('vigente_desde')],
            ['convenio_id' => 'convenio', 'procedimiento_id' => 'procedimiento', 'vigente_desde' => 'vigente desde']);

        try {
            $tarifa = GestionConvenios::guardarTarifa($request->user(), Convenio::findOrFail($datos['convenio_id']), (int) $datos['procedimiento_id'],
                Guaranies::entero($datos['monto']), Carbon::parse(Fecha::aIso($datos['vigente_desde'])));
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.convenios.tarifas.show', $tarifa)
            ->with('status', "Tarifa del convenio guardada: {$tarifa->montoTexto()} desde el ".Fecha::mostrar($tarifa->vigente_desde).'. Las prestaciones ya creadas no cambian.');
    }

    /** Historial: todas las versiones de ese procedimiento en el convenio. */
    public function tarifa(TarifaConvenio $tarifa): View
    {
        $tarifa->load(['procedimiento', 'convenio.responsablePago.persona']);

        return view('admin.convenios.tarifa-show', [
            'tarifa' => $tarifa,
            'versiones' => TarifaConvenio::query()->where('convenio_id', $tarifa->convenio_id)->where('procedimiento_id', $tarifa->procedimiento_id)
                ->with(['moneda', 'usuario.persona'])->orderByDesc('vigente_desde')->orderByDesc('id')->get(),
        ]);
    }

    public function bajaTarifa(Request $request, TarifaConvenio $tarifa): RedirectResponse
    {
        $dia = $request->validate(['ultimo_dia' => ['required', ...Fecha::regla()]], Fecha::mensajes('ultimo_dia'), ['ultimo_dia' => 'último día'])['ultimo_dia'];

        try {
            GestionConvenios::bajaTarifa($tarifa, Carbon::parse(Fecha::aIso($dia)));
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Tarifa dada de baja: desde el día siguiente, ese procedimiento usa la tarifa general.');
    }

    /** Los responsables de pago activos (las aseguradoras), por nombre. */
    private static function responsables(): array
    {
        return ResponsablePago::activos()->with('persona')->get()->sortBy('persona.nombre_completo')
            ->mapWithKeys(fn ($responsable) => [$responsable->id => $responsable->persona->nombre_completo])->all();
    }

    /** Los convenios vigentes, "Aseguradora — Convenio". */
    private static function convenios(): array
    {
        return Convenio::query()->where('estado_id', Estado::idDe(Estado::VIGENTE))->with('responsablePago.persona')->get()
            ->sortBy(fn (Convenio $convenio) => $convenio->nombreCompleto())
            ->mapWithKeys(fn (Convenio $convenio) => [$convenio->id => $convenio->nombreCompleto()])->all();
    }
}
