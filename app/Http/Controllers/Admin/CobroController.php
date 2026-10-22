<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\MedioPago;
use App\Models\Moneda;
use App\Models\Paciente;
use App\Models\Estado;
use App\Models\Prestacion;
use App\Models\SolicitudReversionCobro;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\Guaranies;
use App\Support\Cobros\RevertirCobro;
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
 * confirmar. Sin contenido clínico. La reversión, desde el detalle: "Revertir" (DESACTIVAR, un cobro de su caja abierta) o
 * "Solicitar la reversión" (DESACTIVAR, los demás), y la pestaña de solicitudes pendientes, con su aprobación o rechazo
 * (REVERSIONES_COBRO: Gerencia).
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
        $estado = in_array($request->query('estado'), [Estado::VIGENTE, Estado::REVERTIDO], true) ? $request->query('estado') : null;

        return $this->listado($request, 'admin.cobros', [
            'cobros' => Cobro::query()
                ->join('pacientes', 'pacientes.id', '=', 'cobros.paciente_id')
                ->join('personas', 'personas.id', '=', 'pacientes.persona_id')
                ->when($desde, fn ($query) => $query->where('cobros.cobrado_en', '>=', Carbon::parse($desde, config('app.zona_horaria_local'))->startOfDay()->utc()))
                ->when($hasta, fn ($query) => $query->where('cobros.cobrado_en', '<', Carbon::parse($hasta, config('app.zona_horaria_local'))->addDay()->startOfDay()->utc()))
                ->when($busqueda !== '', fn ($query) => $this->buscarPaciente($query, $busqueda))
                ->when($estado, fn ($query) => $query->where('cobros.estado_id', Estado::idDe($estado)))
                ->orderByDesc('cobros.cobrado_en')->orderByDesc('cobros.id')
                ->select('cobros.*')
                ->with(['paciente.persona', 'cajero.persona', 'lineas.medioPago', 'moneda', 'estado'])->withCount('prestaciones')
                ->paginate(20)->withQueryString(),
            'busqueda' => $busqueda,
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'estado' => $estado],
            'puedeCobrar' => Permisos::puedeRuta('admin.cobros.create'),
            ...$this->pestanas(),
        ]);
    }

    /** Las solicitudes de reversión pendientes (Gerencia), las más antiguas primero, con "Aprobar" en la misma fila. */
    public function reversiones(): View
    {
        return view('admin.cobros.reversiones', [
            'solicitudes' => SolicitudReversionCobro::query()->pendientes()
                ->with(['solicitante.persona', 'cobro.paciente.persona', 'cobro.moneda', 'cobro.lineas.medioPago', 'cobro.cajero.persona'])
                ->orderBy('solicitada_en')->orderBy('id')->get(),
            'puedeRevisar' => Permisos::puedeRuta('admin.cobros.reversiones.aprobar'),
            ...$this->pestanas(),
        ]);
    }

    public function show(Cobro $cobro): View
    {
        $cobro->load(['paciente.persona', 'cajero.persona', 'caja.estado', 'moneda', 'lineas.medioPago', 'estado', 'revertidoPor.persona', 'cajaReversion',
            'prestaciones.prestacion.procedimiento', 'prestaciones.prestacion.profesional.persona',
            'solicitudesReversion' => fn ($query) => $query->orderByDesc('id'), 'solicitudesReversion.estado',
            'solicitudesReversion.solicitante.persona', 'solicitudesReversion.revisadoPor.persona']);
        $usuario = auth()->user();
        $pendiente = $cobro->solicitudesReversion->first(fn (SolicitudReversionCobro $solicitud) => $solicitud->pendiente());
        $libre = ! $cobro->revertido() && $pendiente === null;
        $directa = RevertirCobro::esDirecta($usuario, $cobro);
        $veCaja = fn (?Caja $caja) => $caja !== null && Permisos::puedeRuta('admin.cajas.show')
            && ($caja->usuario_id === $usuario->id || $usuario->tienePermiso('CIERRES_CAJA', 'VER'));

        return view('admin.cobros.show', [
            'cobro' => $cobro,
            'veCaja' => $veCaja($cobro->caja),
            'veCajaReversion' => $veCaja($cobro->cajaReversion),
            'puedeRevertir' => $libre && $directa && Permisos::puedeRuta('admin.cobros.revertir'),
            'puedeSolicitar' => $libre && ! $directa && Permisos::puedeRuta('admin.cobros.solicitar-reversion'),
            'pendiente' => $pendiente,
            'puedeRevisar' => $pendiente !== null && $pendiente->usuario_id !== $usuario->id && Permisos::puedeRuta('admin.cobros.reversiones.aprobar'),
            'sinCajaSolicitante' => $pendiente !== null
                && ! Caja::query()->abiertaDe($pendiente->solicitante)->whereDate('fecha', Fecha::hoy()->format('Y-m-d'))->exists(),
        ]);
    }

    public function revertir(Request $request, Cobro $cobro): RedirectResponse
    {
        $motivo = $this->motivo($request);

        try {
            RevertirCobro::ejecutar($request->user(), $cobro, $motivo);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cobros.show', $cobro)->with('status', "Cobro revertido: se devolvieron {$cobro->totalTexto()} desde su caja.");
    }

    public function solicitarReversion(Request $request, Cobro $cobro): RedirectResponse
    {
        $motivo = $this->motivo($request);

        try {
            RevertirCobro::solicitar($request->user(), $cobro, $motivo);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cobros.show', $cobro)->with('status', 'Solicitud de reversión enviada: queda pendiente de aprobación.');
    }

    public function aprobarReversion(Request $request, SolicitudReversionCobro $solicitud): RedirectResponse
    {
        $nota = $request->validate(['nota' => ['nullable', 'string', 'max:'.RevertirCobro::MOTIVO_MAXIMO]], [], ['nota' => 'nota'])['nota'] ?? null;

        try {
            RevertirCobro::aprobar($request->user(), $solicitud, $nota);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Reversión aprobada: el cobro N.º {$solicitud->cobro_id} quedó revertido.");
    }

    public function rechazarReversion(Request $request, SolicitudReversionCobro $solicitud): RedirectResponse
    {
        $nota = $request->validate(
            ['nota_rechazo' => ['required', 'string', 'min:'.RevertirCobro::MOTIVO_MINIMO, 'max:'.RevertirCobro::MOTIVO_MAXIMO]],
            ['nota_rechazo.required' => 'Indique por qué se rechaza.', 'nota_rechazo.min' => 'Indique por qué se rechaza (al menos '.RevertirCobro::MOTIVO_MINIMO.' caracteres).'],
            ['nota_rechazo' => 'nota'],
        )['nota_rechazo'];

        try {
            RevertirCobro::rechazar($request->user(), $solicitud, $nota);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Solicitud de reversión rechazada.');
    }

    private function motivo(Request $request): string
    {
        return $request->validate(
            ['motivo' => ['required', 'string', 'min:'.RevertirCobro::MOTIVO_MINIMO, 'max:'.RevertirCobro::MOTIVO_MAXIMO]],
            ['motivo.required' => 'Indique el motivo de la reversión.', 'motivo.min' => 'Indique el motivo de la reversión (al menos '.RevertirCobro::MOTIVO_MINIMO.' caracteres).'],
            ['motivo' => 'motivo'],
        )['motivo'];
    }

    /** Las pestañas de Cobros: la de solicitudes de reversión pendientes, solo para quien las revisa (con la cantidad). */
    private function pestanas(): array
    {
        $veReversiones = Permisos::puedeRuta('admin.cobros.reversiones');

        return ['veReversiones' => $veReversiones, 'reversionesPendientes' => $veReversiones ? SolicitudReversionCobro::query()->pendientes()->count() : 0];
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
            'medios' => MedioPago::activos()->orderByDesc('es_efectivo')->orderBy('nombre')->get(),
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
