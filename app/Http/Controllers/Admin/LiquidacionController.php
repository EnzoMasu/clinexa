<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Convenio;
use App\Models\Estado;
use App\Models\LineaLiquidacion;
use App\Models\Liquidacion;
use App\Models\MedioPago;
use App\Models\Moneda;
use App\Models\PagoAseguradora;
use App\Models\Prestacion;
use App\Models\ResponsablePago;
use App\Support\Cobros\Guaranies;
use App\Support\Fecha;
use App\Support\Liquidaciones\ArmarLiquidacion;
use App\Support\Liquidaciones\FlujoLiquidacion;
use App\Support\Liquidaciones\PagosAseguradora;
use App\Support\Liquidaciones\PlanillaLiquidacion;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as RespuestaHttp;

/**
 * Liquidaciones a aseguradoras (docs/liquidaciones-aseguradoras.md), un solo módulo del grupo Económico con tres pestañas:
 * "Liquidaciones" (listado y detalle, con quitar líneas, enviar, aprobar o devolver, anular, la planilla y los pagos), "Por cobrar por
 * aseguradora" y "Prestaciones sin firma" (las que quedan afuera de la liquidación). Armar es una sola pantalla: aseguradora, corte,
 * vista previa y confirmar. Nada se borra. Recepción, Caja, Médico, Enfermería y Compras no tienen el módulo.
 */
class LiquidacionController extends Controller
{
    /** Cuántas prestaciones sin firma se listan (las más antiguas primero); la cantidad total se muestra igual. */
    public const SIN_FIRMA_MAXIMO = 100;

    public function index(Request $request): Response
    {
        $responsableId = filter_var($request->query('aseguradora'), FILTER_VALIDATE_INT) ?: null;
        $estado = array_key_exists((string) $request->query('estado'), Liquidacion::ETIQUETAS) ? (string) $request->query('estado') : null;
        $busqueda = trim((string) $request->query('q'));
        $veRevision = $request->user()->tienePermiso('APROBACION_LIQUIDACIONES', 'VER');

        return $this->listado($request, 'admin.liquidaciones', [
            'liquidaciones' => Liquidacion::query()
                ->when($responsableId, fn ($query) => $query->where('responsable_pago_id', $responsableId))
                ->when($estado, fn ($query) => $query->where('estado_id', Estado::idDe($estado)))
                ->when(ctype_digit($busqueda), fn ($query) => $query->whereKey((int) $busqueda))
                ->with(['responsablePago.persona', 'convenio', 'moneda', 'armadaPor.persona'])
                ->withSum('pagosVigentes', 'monto')
                ->orderByDesc('fecha_corte')->orderByDesc('id')
                ->paginate(20)->withQueryString(),
            'busqueda' => $busqueda,
            'filtros' => ['aseguradora' => $responsableId, 'estado' => $estado],
            'opcionesAseguradora' => self::aseguradoras(),
            'opcionesEstado' => Liquidacion::ETIQUETAS,
            'pendientesDeAprobacion' => $veRevision && ! $request->ajax() ? Liquidacion::where('estado_id', Estado::idDe(Estado::PENDIENTE_APROBACION))->count() : 0,
            'cantidadSinFirma' => $request->ajax() ? 0 : ArmarLiquidacion::sinFirma()->count(),
            'activa' => 'liquidaciones',
        ]);
    }

    /** Pestaña "Por cobrar por aseguradora": lo aprobado, lo pagado y el saldo de cada una. El saldo se calcula, no se guarda. */
    public function porCobrar(): View
    {
        $aprobadas = Liquidacion::query()->aprobadas()->with(['responsablePago.persona', 'moneda'])->withSum('pagosVigentes', 'monto')
            ->orderBy('fecha_corte')->orderBy('id')->get();
        $porAseguradora = $aprobadas->groupBy('responsable_pago_id')->map(fn ($liquidaciones) => [
            'aseguradora' => $liquidaciones->first()->aseguradoraTexto(),
            'responsableId' => $liquidaciones->first()->responsable_pago_id,
            'total' => $liquidaciones->sum(fn (Liquidacion $liquidacion) => $liquidacion->totalEntero()),
            'pagado' => $liquidaciones->sum(fn (Liquidacion $liquidacion) => $liquidacion->pagado()),
            'saldo' => $liquidaciones->sum(fn (Liquidacion $liquidacion) => $liquidacion->saldo()),
            'conSaldo' => $liquidaciones->filter(fn (Liquidacion $liquidacion) => $liquidacion->saldo() > 0)->values(),
            'pendientesDeFacturar' => $liquidaciones->where('facturacion', Liquidacion::PENDIENTE_DE_FACTURAR)->count(),
        ])->sortBy('aseguradora')->values();

        return view('admin.liquidaciones.por-cobrar', [
            'porAseguradora' => $porAseguradora,
            'moneda' => Moneda::guarani(),
            'activa' => 'por-cobrar',
        ]);
    }

    /** Pestaña "Prestaciones sin firma": las que no entran en la liquidación hasta recibir la firma, para gestionarlas. */
    public function sinFirma(Request $request): View
    {
        $responsableId = filter_var($request->query('aseguradora'), FILTER_VALIDATE_INT) ?: null;
        $consulta = ArmarLiquidacion::sinFirma($responsableId);

        return view('admin.liquidaciones.sin-firma', [
            'cantidad' => (clone $consulta)->count(),
            'prestaciones' => self::conDatosSinFirma($consulta)->limit(self::SIN_FIRMA_MAXIMO)->get(),
            'filtros' => ['aseguradora' => $responsableId],
            'opcionesAseguradora' => self::aseguradoras(),
            'activa' => 'sin-firma',
        ]);
    }

    /** Armar, en una sola pantalla: aseguradora y corte; con los dos, la vista previa, las que quedan afuera y "Confirmar". */
    public function create(Request $request): View
    {
        $aseguradoras = self::aseguradoras();
        $responsableId = filter_var($request->query('aseguradora'), FILTER_VALIDATE_INT) ?: null;
        $corteTexto = trim((string) $request->query('corte', Fecha::mostrar(Fecha::hoy())));
        $error = null;
        $corte = null;
        if ($responsableId !== null && ! array_key_exists($responsableId, $aseguradoras)) {
            $error = 'Elija una aseguradora con convenio.';
        } elseif (! preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $corteTexto) || ($iso = self::iso($corteTexto)) === null) {
            $error = 'Escriba la fecha de corte con el formato dd/mm/aaaa.';
        } else {
            $corte = Carbon::parse($iso);
            try {
                ArmarLiquidacion::verificarCorte($corte);
            } catch (AccionRechazada $e) {
                [$error, $corte] = [$e->getMessage(), null];
            }
        }

        $vista = ['aseguradoras' => $aseguradoras, 'responsableId' => $responsableId, 'corteTexto' => $corteTexto, 'error' => $responsableId ? $error : null,
            'moneda' => Moneda::guarani(), 'prestaciones' => null, 'sinFirma' => null, 'abierta' => null];
        if ($responsableId !== null && $corte !== null) {
            $prestaciones = ArmarLiquidacion::vistaPrevia($responsableId, $corte);
            $sinFirma = ArmarLiquidacion::sinFirma($responsableId, $corte);
            $vista = [...$vista,
                'prestaciones' => $prestaciones,
                'total' => $prestaciones->sum(fn (Prestacion $prestacion) => ArmarLiquidacion::montoAseguradora($prestacion)),
                'cantidadSinFirma' => (clone $sinFirma)->count(),
                'sinFirma' => self::conDatosSinFirma($sinFirma)->limit(self::SIN_FIRMA_MAXIMO)->get(),
                'abierta' => Liquidacion::query()->abiertas()->where('responsable_pago_id', $responsableId)->first(),
                'veCobertura' => CoberturaController::puedeVer(),
            ];
        }

        return view('admin.liquidaciones.create', $vista);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'responsable_pago_id' => ['required', 'integer'],
            'fecha_corte' => ['required', ...Fecha::regla()],
            'cantidad' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:0'],
        ], Fecha::mensajes('fecha_corte'), ['responsable_pago_id' => 'aseguradora', 'fecha_corte' => 'fecha de corte']);

        $responsable = array_key_exists((int) $datos['responsable_pago_id'], self::aseguradoras()) ? ResponsablePago::find($datos['responsable_pago_id']) : null;
        if ($responsable === null) {
            return back()->withInput()->with('error', 'Elija una aseguradora con convenio.');
        }

        try {
            $liquidacion = ArmarLiquidacion::ejecutar($request->user(), $responsable, Carbon::parse(Fecha::aIso($datos['fecha_corte'])),
                (int) $datos['cantidad'], (int) $datos['total']);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.liquidaciones.show', $liquidacion)
            ->with('status', "Liquidación N.º {$liquidacion->id} armada: {$liquidacion->formatear($liquidacion->totalEntero())}. Revísela y envíela a aprobación.");
    }

    public function show(Request $request, Liquidacion $liquidacion): View
    {
        $liquidacion->load(['responsablePago.persona', 'convenio', 'moneda', 'armadaPor.persona', 'enviadaPor.persona', 'aprobadaPor.persona',
            'devueltaPor.persona', 'anuladaPor.persona', 'lineas.liberadaPor.persona', 'pagos.medioPago', 'pagos.usuario.persona', 'pagos.anuladoPor.persona']);
        $usuario = $request->user();
        $revisa = Permisos::puedeRuta('admin.liquidaciones.aprobar');
        $propia = (int) $liquidacion->armada_por === (int) $usuario->id;

        return view('admin.liquidaciones.show', [
            'liquidacion' => $liquidacion,
            'lineas' => $liquidacion->lineas->sortBy([['fecha_servicio', 'asc'], ['id', 'asc']])->values(),
            'puedeQuitar' => $liquidacion->enPreparacion() && Permisos::puedeRuta('admin.liquidaciones.lineas.quitar'),
            'puedeEnviar' => $liquidacion->enPreparacion() && Permisos::puedeRuta('admin.liquidaciones.enviar'),
            'puedeRevisar' => $liquidacion->pendienteDeAprobacion() && $revisa && ! $propia,
            'esPropia' => $liquidacion->pendienteDeAprobacion() && $revisa && $propia,
            'puedeAnular' => ! $liquidacion->anulada() && $liquidacion->pagos->every(fn (PagoAseguradora $pago) => $pago->anulado())
                && Permisos::puedeRuta('admin.liquidaciones.anular') && (! $liquidacion->aprobada() || $revisa),
            'puedePlanilla' => ! $liquidacion->anulada() && Permisos::puedeRuta('admin.liquidaciones.planilla'),
            'vePagos' => $usuario->tienePermiso('PAGOS_ASEGURADORAS', 'VER'),
            'puedePagar' => $liquidacion->aprobada() && $liquidacion->saldo() > 0 && Permisos::puedeRuta('admin.liquidaciones.pagos.store'),
            'puedeAnularPago' => Permisos::puedeRuta('admin.liquidaciones.pagos.anular'),
            'medios' => MedioPago::activos()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'medioSugerido' => MedioPago::where('codigo', 'TRANSFERENCIA')->activos()->value('id'),
            'vePrestaciones' => Permisos::puedeRuta('admin.prestaciones.show'),
        ]);
    }

    public function quitarLinea(Request $request, LineaLiquidacion $linea): RedirectResponse
    {
        $motivo = $this->texto($request, 'motivo_quitar', 'motivo');

        try {
            FlujoLiquidacion::quitarLinea($request->user(), $linea, $motivo);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Prestación quitada de la liquidación: queda libre para la siguiente.');
    }

    public function enviar(Request $request, Liquidacion $liquidacion): RedirectResponse
    {
        try {
            FlujoLiquidacion::enviar($request->user(), $liquidacion);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Liquidación enviada a aprobación.');
    }

    public function aprobar(Request $request, Liquidacion $liquidacion): RedirectResponse
    {
        try {
            FlujoLiquidacion::aprobar($request->user(), $liquidacion);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Liquidación aprobada: queda bloqueada y pendiente de facturar.');
    }

    public function devolver(Request $request, Liquidacion $liquidacion): RedirectResponse
    {
        $nota = $this->texto($request, 'nota_devolucion', 'nota');

        try {
            FlujoLiquidacion::devolver($request->user(), $liquidacion, $nota);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Liquidación devuelta: vuelve a estar en preparación.');
    }

    /** Anular: con motivo y sin pagos vigentes. Una aprobada, solo quien aprueba (Gerencia o el Administrador). */
    public function anular(Request $request, Liquidacion $liquidacion): RedirectResponse
    {
        $motivo = $this->texto($request, 'motivo_anulacion', 'motivo de la anulación');
        if ($liquidacion->aprobada() && ! $request->user()->tienePermiso('APROBACION_LIQUIDACIONES', 'EDITAR')) {
            return back()->with('error', 'Una liquidación aprobada solo la anula quien aprueba las liquidaciones.');
        }

        try {
            FlujoLiquidacion::anular($request->user(), $liquidacion, $motivo);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Liquidación anulada: sus prestaciones quedan libres para la siguiente.');
    }

    /** La planilla (PDF al vuelo, sin nada clínico). */
    public function planilla(Liquidacion $liquidacion): RespuestaHttp
    {
        try {
            return PlanillaLiquidacion::ejecutar($liquidacion);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function registrarPago(Request $request, Liquidacion $liquidacion): RedirectResponse
    {
        $datos = $request->validate([
            'monto' => Guaranies::regla(),
            'fecha' => ['required', ...Fecha::regla(hastaHoy: true)],
            'medio_pago_id' => ['required', 'integer'],
            'referencia' => ['nullable', 'string', 'max:'.PagosAseguradora::REFERENCIA_MAXIMA],
        ], ['monto.regex' => Guaranies::MENSAJE, ...Fecha::mensajes('fecha')], ['medio_pago_id' => 'medio de pago']);

        try {
            $pago = PagosAseguradora::registrar($request->user(), $liquidacion, Guaranies::entero($datos['monto']), Carbon::parse(Fecha::aIso($datos['fecha'])),
                (int) $datos['medio_pago_id'], $datos['referencia'] ?? null);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $liquidacion = $pago->liquidacion;

        return back()->with('status', "Pago registrado: {$liquidacion->formatear($pago->montoEntero())}. Saldo: {$liquidacion->formatear($liquidacion->saldo())}.");
    }

    public function anularPago(Request $request, PagoAseguradora $pago): RedirectResponse
    {
        $motivo = $this->texto($request, 'motivo_pago', 'motivo de la anulación');

        try {
            PagosAseguradora::anular($request->user(), $pago, $motivo);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Pago anulado: el saldo se recalculó.');
    }

    private function texto(Request $request, string $campo, string $nombre): string
    {
        return $request->validate(
            [$campo => ['required', 'string', 'min:'.FlujoLiquidacion::MOTIVO_MINIMO, 'max:'.FlujoLiquidacion::MOTIVO_MAXIMO]],
            ["{$campo}.required" => "Indique el {$nombre}.", "{$campo}.min" => "Indique el {$nombre} (al menos ".FlujoLiquidacion::MOTIVO_MINIMO.' caracteres).'],
            [$campo => $nombre],
        )[$campo];
    }

    /** Las aseguradoras: los responsables de pago con algún convenio, por nombre (id => nombre). */
    private static function aseguradoras(): array
    {
        return ResponsablePago::query()->whereIn('id', Convenio::query()->select('responsable_pago_id'))->with('persona')->get()
            ->sortBy('persona.nombre_completo')->mapWithKeys(fn (ResponsablePago $responsable) => [$responsable->id => $responsable->persona->nombre_completo])->all();
    }

    private static function conDatosSinFirma($query)
    {
        return $query->with(['paciente.persona', 'procedimiento', 'moneda', 'responsablePago.persona'])
            ->orderBy('prestaciones.fecha_servicio')->orderBy('prestaciones.id');
    }

    private static function iso(string $texto): ?string
    {
        try {
            $fecha = Carbon::createFromFormat('!'.Fecha::FORMATO, $texto);
        } catch (\Throwable) {
            return null;
        }

        return $fecha && $fecha->format(Fecha::FORMATO) === $texto ? $fecha->format('Y-m-d') : null;
    }
}
