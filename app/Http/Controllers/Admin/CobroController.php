<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\MedioPago;
use App\Models\Moneda;
use App\Models\Paciente;
use App\Models\Prestacion;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\Guaranies;
use App\Support\Fecha;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cobros (docs/cobros-caja.md): el listado y el detalle (VER sobre COBROS: Caja, Gerencia, Facturación) y la pantalla de cobro en
 * un solo flujo (CREAR: Caja): buscar la paciente, ver sus prestaciones sin cobrar, tildar cuáles, cargar los medios y montos y
 * confirmar. Sin contenido clínico.
 */
class CobroController extends Controller
{
    /** Las líneas de pago que ofrece el formulario (las vacías no cuentan). */
    public const LINEAS = 3;

    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $desde = $this->fecha($request->query('desde'));
        $hasta = $this->fecha($request->query('hasta'));

        return $this->listado($request, 'admin.cobros', [
            'cobros' => Cobro::query()
                ->join('pacientes', 'pacientes.id', '=', 'cobros.paciente_id')
                ->join('personas', 'personas.id', '=', 'pacientes.persona_id')
                ->when($desde, fn ($query) => $query->where('cobros.cobrado_en', '>=', Carbon::parse($desde, config('app.zona_horaria_local'))->startOfDay()->utc()))
                ->when($hasta, fn ($query) => $query->where('cobros.cobrado_en', '<', Carbon::parse($hasta, config('app.zona_horaria_local'))->addDay()->startOfDay()->utc()))
                ->when($busqueda !== '', fn ($query) => $this->buscarPaciente($query, $busqueda))
                ->orderByDesc('cobros.cobrado_en')->orderByDesc('cobros.id')
                ->select('cobros.*')
                ->with(['paciente.persona', 'cajero.persona', 'lineas.medioPago', 'moneda'])->withCount('prestaciones')
                ->paginate(20)->withQueryString(),
            'busqueda' => $busqueda,
            'filtros' => ['desde' => $desde, 'hasta' => $hasta],
            'puedeCobrar' => Permisos::puedeRuta('admin.cobros.create'),
        ]);
    }

    public function show(Cobro $cobro): View
    {
        $cobro->load(['paciente.persona', 'cajero.persona', 'caja', 'moneda', 'lineas.medioPago',
            'prestaciones.prestacion.procedimiento', 'prestaciones.prestacion.profesional.persona']);

        return view('admin.cobros.show', [
            'cobro' => $cobro,
            'veCaja' => Permisos::puedeRuta('admin.cajas.show') && ($cobro->caja->usuario_id === auth()->id() || auth()->user()->tienePermiso('CIERRES_CAJA', 'VER')),
        ]);
    }

    /**
     * La pantalla de cobro: sin paciente elegida, las pacientes con prestaciones sin cobrar (con la búsqueda); con una, sus
     * prestaciones sin cobrar para tildar y las líneas de pago.
     */
    public function create(Request $request): View
    {
        $usuario = $request->user();
        $caja = Caja::query()->abiertaDe($usuario)->first();
        $sinCaja = match (true) {
            $caja === null => Cobrar::SIN_CAJA,
            $caja->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d') => Cobrar::deOtroDia($caja),
            default => null,
        };
        $busqueda = trim((string) $request->query('q'));
        $paciente = filter_var($request->query('paciente'), FILTER_VALIDATE_INT)
            ? Paciente::with('persona')->find((int) $request->query('paciente')) : null;

        return view('admin.cobros.create', [
            'sinCaja' => $sinCaja,
            'busqueda' => $busqueda,
            'paciente' => $paciente,
            'pacientes' => $paciente ? collect() : $this->pacientesConPendientes($busqueda),
            'prestaciones' => $paciente
                ? Cobrar::pendientes()->where('prestaciones.paciente_id', $paciente->id)->with(['procedimiento', 'profesional.persona', 'moneda'])
                    ->orderBy('prestaciones.fecha_servicio')->orderBy('prestaciones.id')->get()
                : collect(),
            'medios' => MedioPago::activos()->orderByRaw("CASE WHEN codigo = 'EFECTIVO' THEN 0 ELSE 1 END")->orderBy('nombre')->get(),
            'moneda' => Moneda::guarani(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'paciente_id' => ['required', 'integer'],
            'prestaciones' => ['required', 'array', 'min:1'],
            'prestaciones.*' => ['integer'],
            'lineas' => ['required', 'array'],
            'lineas.*.medio_pago_id' => ['nullable', 'integer'],
            'lineas.*.monto' => Guaranies::regla(requerido: false),
            'lineas.*.referencia' => ['nullable', 'string', 'max:'.Cobrar::REFERENCIA_MAXIMA],
        ], [
            'prestaciones.required' => 'Elija al menos una prestación para cobrar.',
            'lineas.*.monto.regex' => Guaranies::MENSAJE,
        ], ['lineas.*.monto' => 'monto', 'lineas.*.referencia' => 'referencia']);

        // Las líneas vacías no cuentan; una a medias (medio sin monto o monto sin medio) se rechaza.
        $lineas = [];
        foreach ($datos['lineas'] as $linea) {
            $medio = $linea['medio_pago_id'] ?? null;
            $monto = trim((string) ($linea['monto'] ?? ''));
            if ($medio === null && $monto === '') {
                continue;
            }
            if ($medio === null || $monto === '') {
                return back()->withInput()->with('error', 'Cada pago necesita el medio y el monto.');
            }
            $lineas[] = ['medio_pago_id' => (int) $medio, 'monto' => Guaranies::entero($monto), 'referencia' => $linea['referencia'] ?? null];
        }

        try {
            $cobro = Cobrar::ejecutar($request->user(), (int) $datos['paciente_id'], $datos['prestaciones'], $lineas);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cobros.show', $cobro)->with('status', "Cobro registrado: {$cobro->totalTexto()}.");
    }

    /** Las pacientes con prestaciones sin cobrar (hasta 30, las de prestación más antigua primero), con la cantidad y el total. */
    private function pacientesConPendientes(string $busqueda)
    {
        $pendientes = Cobrar::pendientes()
            ->join('pacientes', 'pacientes.id', '=', 'prestaciones.paciente_id')
            ->join('personas', 'personas.id', '=', 'pacientes.persona_id')
            ->when($busqueda !== '', fn ($query) => $this->buscarPaciente($query, $busqueda))
            ->groupBy('prestaciones.paciente_id')
            ->select('prestaciones.paciente_id', DB::raw('COUNT(*) AS cantidad'), DB::raw('SUM(prestaciones.monto) AS total'), DB::raw('MIN(prestaciones.fecha_servicio) AS desde'))
            ->orderBy('desde')->limit(30)->toBase()->get();
        $pacientes = Paciente::with('persona')->whereKey($pendientes->pluck('paciente_id'))->get()->keyBy('id');

        return $pendientes->map(fn ($fila) => ['paciente' => $pacientes[$fila->paciente_id], 'cantidad' => (int) $fila->cantidad, 'total' => (int) round((float) $fila->total)]);
    }

    private function buscarPaciente($query, string $busqueda)
    {
        return $query->where(fn ($query) => $query
            ->whereLike('personas.apellidos', "%{$busqueda}%")
            ->orWhereLike('personas.nombres', "%{$busqueda}%")
            ->orWhereLike('personas.nro_documento', "{$busqueda}%")
            ->orWhereLike('pacientes.nro_ficha', "%{$busqueda}%"));
    }

    /** Un filtro de fecha dd/mm/aaaa válido, en ISO; si no, null. */
    private function fecha(mixed $texto): ?string
    {
        $texto = (string) $texto;

        return preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) && Carbon::canBeCreatedFromFormat($texto, Fecha::FORMATO) ? Fecha::aIso($texto) : null;
    }
}
