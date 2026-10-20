<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Estado;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Tarifa;
use App\Support\Fecha;
use App\Support\Tarifas\AltaTarifa;
use App\Support\Tarifas\CambiarTarifa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tarifas (docs/tarifas-prestaciones.md): el listado (las vigentes, o también las históricas), el alta, "Cambiar tarifa" (cierra
 * la vigente y abre otra) y el historial de un procedimiento y profesional. Nunca se editan ni se borran.
 */
class TarifaController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $conHistoricas = $request->query('estado') === 'TODAS';

        $tarifas = Tarifa::query()
            ->join('procedimientos', 'procedimientos.id', '=', 'tarifas.procedimiento_id')
            ->leftJoin('profesionales', 'profesionales.id', '=', 'tarifas.profesional_id')
            ->leftJoin('personas', 'personas.id', '=', 'profesionales.persona_id')
            ->unless($conHistoricas, fn ($query) => $query->where('tarifas.estado_id', Estado::idDe(Estado::VIGENTE)))
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('procedimientos.nombre', "%{$busqueda}%")
                ->orWhereLike('procedimientos.codigo', "%{$busqueda}%")
                ->orWhereLike('personas.apellidos', "%{$busqueda}%")
                ->orWhereLike('personas.nombres', "%{$busqueda}%")))
            ->orderBy('procedimientos.nombre')
            ->orderByRaw('tarifas.profesional_id IS NOT NULL')
            ->orderBy('personas.apellidos')
            ->orderByDesc('tarifas.vigente_desde')
            ->orderByDesc('tarifas.id')
            ->select('tarifas.*')
            ->with(['procedimiento', 'profesional.persona', 'moneda'])
            ->paginate(20)->withQueryString();

        return $this->listado($request, 'admin.tarifas', [
            'tarifas' => $tarifas,
            'busqueda' => $busqueda,
            'filtros' => ['estado' => $conHistoricas ? 'TODAS' : 'VIGENTES'],
        ]);
    }

    public function create(): View
    {
        return view('admin.tarifas.form', [
            'procedimientos' => Procedimiento::activos()->orderBy('nombre')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->nombre} ({$p->codigo})"])->all(),
            'profesionales' => Profesional::activos()->with('persona')->get()->sortBy('persona.nombre_completo')
                ->mapWithKeys(fn ($p) => [$p->id => $p->persona->nombre_completo])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $activo = Estado::idDe(Estado::ACTIVO);
        $datos = $request->validate([
            'procedimiento_id' => ['required', 'integer', Rule::exists('procedimientos', 'id')->where('estado_id', $activo)],
            'profesional_id' => ['nullable', 'integer', Rule::exists('profesionales', 'id')->where('estado_id', $activo)],
            'monto' => self::reglaMonto(),
            'vigente_desde' => ['required', ...Fecha::regla()],
        ], [...self::mensajesMonto(), ...Fecha::mensajes('vigente_desde')], self::ATRIBUTOS);

        try {
            $tarifa = AltaTarifa::ejecutar($request->user(), (int) $datos['procedimiento_id'], isset($datos['profesional_id']) ? (int) $datos['profesional_id'] : null,
                self::monto($datos['monto']), Carbon::parse(Fecha::aIso($datos['vigente_desde'])));
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.tarifas.index')->with('status', "Tarifa creada: {$tarifa->montoTexto()}.");
    }

    /** Historial: todas las versiones de ese procedimiento y profesional, de la más nueva a la más vieja. */
    public function show(Tarifa $tarifa): View
    {
        $tarifa->load(['procedimiento', 'profesional.persona']);

        return view('admin.tarifas.show', [
            'tarifa' => $tarifa,
            'versiones' => Tarifa::query()->deLaClave($tarifa->procedimiento_id, $tarifa->profesional_id)
                ->with(['moneda', 'usuario.persona'])->orderByDesc('vigente_desde')->orderByDesc('id')->get(),
        ]);
    }

    public function cambiar(Tarifa $tarifa): View|RedirectResponse
    {
        if (! $tarifa->vigente()) {
            return redirect()->route('admin.tarifas.show', $tarifa)->with('error', CambiarTarifa::YA_NO_VIGENTE);
        }
        $tarifa->load(['procedimiento', 'profesional.persona', 'moneda']);

        return view('admin.tarifas.cambiar', [
            'tarifa' => $tarifa,
            'minimo' => max(Fecha::hoy()->format('Y-m-d'), $tarifa->vigente_desde->format('Y-m-d')),
        ]);
    }

    public function guardarCambio(Request $request, Tarifa $tarifa): RedirectResponse
    {
        $datos = $request->validate([
            'monto' => self::reglaMonto(),
            'vigente_desde' => ['required', ...Fecha::regla()],
        ], [...self::mensajesMonto(), ...Fecha::mensajes('vigente_desde')], self::ATRIBUTOS);

        try {
            $nueva = CambiarTarifa::ejecutar($request->user(), $tarifa, self::monto($datos['monto']), Carbon::parse(Fecha::aIso($datos['vigente_desde'])));
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.tarifas.show', $nueva)
            ->with('status', "Tarifa cambiada: {$nueva->montoTexto()} desde el ".Fecha::mostrar($nueva->vigente_desde).'. Las prestaciones ya tarifadas no cambian.');
    }

    private const ATRIBUTOS = ['procedimiento_id' => 'procedimiento', 'profesional_id' => 'profesional', 'vigente_desde' => 'vigente desde'];

    /** Guaraníes enteros, con o sin puntos de miles ("150000" o "150.000"). */
    private static function reglaMonto(): array
    {
        return ['required', 'string', 'regex:/^(\d{1,3}(\.\d{3})+|\d{1,12})$/', function (string $atributo, mixed $valor, \Closure $falla) {
            $monto = self::monto((string) $valor);
            if ($monto < 1 || $monto > Tarifa::MONTO_MAXIMO) {
                $falla('El monto debe ser mayor que cero.');
            }
        }];
    }

    private static function mensajesMonto(): array
    {
        return ['monto.regex' => 'Escriba el monto en guaraníes, sin decimales (por ejemplo, 150000 o 150.000).'];
    }

    private static function monto(string $texto): int
    {
        return (int) str_replace('.', '', trim($texto));
    }
}
