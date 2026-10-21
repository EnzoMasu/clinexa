<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\AcuerdoReparto;
use App\Models\Equipo;
use App\Models\Estado;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Support\Fecha;
use App\Support\Repartos\AltaAcuerdo;
use App\Support\Repartos\CambiarAcuerdo;
use App\Support\Repartos\Porcentaje;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Acuerdos de reparto (docs/reparto-economico.md): el listado (los vigentes, o también los históricos; del profesional o de
 * equipos), el alta, "Cambiar porcentaje" (cierra el vigente y abre otro) y el historial de una clave. Nunca se editan ni se
 * borran. Solo Administrador y Gerencia (módulo ACUERDOS_REPARTO).
 */
class AcuerdoRepartoController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $conHistoricos = $request->query('estado') === 'TODOS';
        $tipo = array_key_exists((string) $request->query('tipo'), AcuerdoReparto::TIPOS) ? (string) $request->query('tipo') : null;

        $acuerdos = AcuerdoReparto::query()
            ->leftJoin('profesionales', 'profesionales.id', '=', 'acuerdos_reparto.profesional_id')
            ->leftJoin('personas', 'personas.id', '=', 'profesionales.persona_id')
            ->leftJoin('procedimientos', 'procedimientos.id', '=', 'acuerdos_reparto.procedimiento_id')
            ->leftJoin('equipos', 'equipos.id', '=', 'acuerdos_reparto.equipo_id')
            ->unless($conHistoricos, fn ($query) => $query->where('acuerdos_reparto.estado_id', Estado::idDe(Estado::VIGENTE)))
            ->when($tipo, fn ($query) => $query->where('acuerdos_reparto.tipo', $tipo))
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('personas.apellidos', "%{$busqueda}%")
                ->orWhereLike('personas.nombres', "%{$busqueda}%")
                ->orWhereLike('procedimientos.nombre', "%{$busqueda}%")
                ->orWhereLike('equipos.nombre', "%{$busqueda}%")))
            ->orderBy('acuerdos_reparto.tipo', 'desc') // PROFESIONAL antes que EQUIPO
            ->orderBy('personas.apellidos')->orderBy('equipos.nombre')
            ->orderByRaw('acuerdos_reparto.procedimiento_id IS NOT NULL')->orderBy('procedimientos.nombre')
            ->orderByDesc('acuerdos_reparto.vigente_desde')->orderByDesc('acuerdos_reparto.id')
            ->select('acuerdos_reparto.*')
            ->with(['profesional.persona', 'procedimiento', 'equipo.dueno.persona'])
            ->paginate(20)->withQueryString();

        return $this->listado($request, 'admin.acuerdos-reparto', [
            'acuerdos' => $acuerdos,
            'busqueda' => $busqueda,
            'filtros' => ['estado' => $conHistoricos ? 'TODOS' : 'VIGENTES', 'tipo' => $tipo],
        ]);
    }

    public function create(Request $request): View
    {
        $profesionales = Profesional::activos()->with('persona')->get()->sortBy('persona.nombre_completo')
            ->mapWithKeys(fn ($p) => [$p->id => $p->persona->nombre_completo])->all();
        // Equipo si lo pide el enlace viejo (?tipo=EQUIPO) o si no hay profesionales: es el único tipo posible.
        $tipo = $request->query('tipo') === AcuerdoReparto::EQUIPO || $profesionales === [] ? AcuerdoReparto::EQUIPO : AcuerdoReparto::PROFESIONAL;

        return view('admin.acuerdos-reparto.form', [
            'tipo' => old('tipo', $tipo),
            'profesionales' => $profesionales,
            'procedimientos' => Procedimiento::activos()->orderBy('nombre')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->nombre} ({$p->codigo})"])->all(),
            'equipos' => Equipo::activos()->with('dueno.persona')->orderBy('nombre')->get()
                ->mapWithKeys(fn ($e) => [$e->id => "{$e->nombre} (dueño: {$e->duenoTexto()})"])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $activo = Estado::idDe(Estado::ACTIVO);
        $esEquipo = $request->input('tipo') === AcuerdoReparto::EQUIPO;
        // El formulario trae los campos de los dos tipos: se descartan los del tipo no elegido.
        $request->merge($esEquipo ? ['profesional_id' => null, 'procedimiento_id' => null] : ['equipo_id' => null]);
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(AcuerdoReparto::TIPOS))],
            'profesional_id' => [Rule::requiredIf(! $esEquipo), 'nullable', 'integer', Rule::exists('profesionales', 'id')->where('estado_id', $activo)],
            'procedimiento_id' => ['nullable', 'integer', Rule::exists('procedimientos', 'id')->where('estado_id', $activo)],
            'equipo_id' => [Rule::requiredIf($esEquipo), 'nullable', 'integer', Rule::exists('equipos', 'id')->where('estado_id', $activo)],
            'porcentaje' => self::reglaPorcentaje(),
            'vigente_desde' => ['required', ...Fecha::regla()],
        ], [...self::mensajesPorcentaje(), ...Fecha::mensajes('vigente_desde')], self::ATRIBUTOS);

        try {
            $acuerdo = AltaAcuerdo::ejecutar($request->user(), $datos['tipo'],
                isset($datos['profesional_id']) ? (int) $datos['profesional_id'] : null,
                isset($datos['procedimiento_id']) ? (int) $datos['procedimiento_id'] : null,
                isset($datos['equipo_id']) ? (int) $datos['equipo_id'] : null,
                Porcentaje::desdeTexto($datos['porcentaje']), Carbon::parse(Fecha::aIso($datos['vigente_desde'])));
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.acuerdos-reparto.index')->with('status', "Acuerdo creado: {$acuerdo->porcentajeTexto()}.");
    }

    /** Historial: todas las versiones de esa clave, de la más nueva a la más vieja. */
    public function show(AcuerdoReparto $acuerdo): View
    {
        $acuerdo->load(['profesional.persona', 'procedimiento', 'equipo.dueno.persona']);

        return view('admin.acuerdos-reparto.show', [
            'acuerdo' => $acuerdo,
            'versiones' => AcuerdoReparto::query()->deLaClave($acuerdo->tipo, $acuerdo->profesional_id, $acuerdo->procedimiento_id, $acuerdo->equipo_id)
                ->with('usuario.persona')->orderByDesc('vigente_desde')->orderByDesc('id')->get(),
        ]);
    }

    public function cambiar(AcuerdoReparto $acuerdo): View|RedirectResponse
    {
        if (! $acuerdo->vigente()) {
            return redirect()->route('admin.acuerdos-reparto.show', $acuerdo)->with('error', CambiarAcuerdo::YA_NO_VIGENTE);
        }
        $acuerdo->load(['profesional.persona', 'procedimiento', 'equipo.dueno.persona']);

        return view('admin.acuerdos-reparto.cambiar', [
            'acuerdo' => $acuerdo,
            'minimo' => max(Fecha::hoy()->format('Y-m-d'), $acuerdo->vigente_desde->format('Y-m-d')),
        ]);
    }

    public function guardarCambio(Request $request, AcuerdoReparto $acuerdo): RedirectResponse
    {
        $datos = $request->validate([
            'porcentaje' => self::reglaPorcentaje(),
            'vigente_desde' => ['required', ...Fecha::regla()],
        ], [...self::mensajesPorcentaje(), ...Fecha::mensajes('vigente_desde')], self::ATRIBUTOS);

        try {
            $nuevo = CambiarAcuerdo::ejecutar($request->user(), $acuerdo, Porcentaje::desdeTexto($datos['porcentaje']), Carbon::parse(Fecha::aIso($datos['vigente_desde'])));
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.acuerdos-reparto.show', $nuevo)
            ->with('status', "Porcentaje cambiado: {$nuevo->porcentajeTexto()} desde el ".Fecha::mostrar($nuevo->vigente_desde).'. Los repartos ya hechos no cambian.');
    }

    private const ATRIBUTOS = ['profesional_id' => 'profesional', 'procedimiento_id' => 'procedimiento', 'equipo_id' => 'equipo', 'vigente_desde' => 'vigente desde'];

    /** De 0 a 100, con hasta dos decimales ("35", "35,5" o "35.50"). */
    private static function reglaPorcentaje(): array
    {
        return ['required', 'string', 'regex:'.Porcentaje::REGEX, function (string $atributo, mixed $valor, \Closure $falla) {
            if ((float) Porcentaje::desdeTexto((string) $valor) > 100) {
                $falla(Porcentaje::MENSAJE);
            }
        }];
    }

    private static function mensajesPorcentaje(): array
    {
        return ['porcentaje.regex' => Porcentaje::MENSAJE];
    }
}
