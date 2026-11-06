<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Equipo;
use App\Models\Estado;
use App\Models\LineaLiquidacionBeneficiario;
use App\Models\LineaReparto;
use App\Models\LiquidacionBeneficiario;
use App\Models\MedioPago;
use App\Models\Moneda;
use App\Models\PagoBeneficiario;
use App\Models\Profesional;
use App\Models\Proveedor;
use App\Models\RepartoPrestacion;
use App\Support\Cobros\Guaranies;
use App\Support\Cobros\RegistrarSalida;
use App\Support\Fecha;
use App\Support\Grilla\Grilla;
use App\Support\LiquidacionesBeneficiarios\ArmarLiquidacionBeneficiario;
use App\Support\LiquidacionesBeneficiarios\Elegibilidad;
use App\Support\LiquidacionesBeneficiarios\FlujoLiquidacionBeneficiario;
use App\Support\LiquidacionesBeneficiarios\PagosBeneficiario;
use App\Support\LiquidacionesBeneficiarios\PlanillaLiquidacionBeneficiario;
use App\Support\LiquidacionesBeneficiarios\ResumenBeneficiarios;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as RespuestaHttp;

/**
 * Liquidaciones a beneficiarios del reparto (docs/liquidacion-beneficiarios.md), un módulo del grupo Económico con pestañas:
 * "Liquidaciones" (listado y detalle con todas las acciones), "Por pagar por beneficiario", "Pendientes de liquidar" (con el motivo) y
 * "Resumen por período" (el servicio de consulta del objetivo 14). Armar es una sola pantalla: beneficiario, corte, vista previa y
 * confirmar. Nada se borra; nada clínico.
 */
class LiquidacionBeneficiarioController extends Controller
{
    /** Cuántas líneas pendientes se listan (las más antiguas primero); la cantidad total se muestra igual. */
    public const PENDIENTES_MAXIMO = 100;

    public function index(Request $request): Response
    {
        $beneficiario = self::beneficiarioDeTexto((string) $request->query('beneficiario'));
        $estado = array_key_exists((string) $request->query('estado'), LiquidacionBeneficiario::ETIQUETAS) ? (string) $request->query('estado') : null;
        $busqueda = trim((string) $request->query('q'));

        $aprobadas = implode(', ', array_map(fn ($codigo) => Estado::idDe($codigo), LiquidacionBeneficiario::APROBADAS));
        $saldo = DB::raw("CASE WHEN liquidaciones_beneficiario.estado_id IN ({$aprobadas}) THEN liquidaciones_beneficiario.total - COALESCE((SELECT SUM(p.monto) FROM pagos_beneficiario p WHERE p.liquidacion_beneficiario_id = liquidaciones_beneficiario.id AND p.anulado_en IS NULL), 0) END");
        $grilla = Grilla::de($request)
            ->numero('numero', 'N.º', 'liquidaciones_beneficiario.id')
            ->opciones('tipo', 'Tipo', 'liquidaciones_beneficiario.beneficiario', LiquidacionBeneficiario::BENEFICIARIOS)
            ->fija('beneficiario', 'Beneficiario')
            ->fecha('corte', 'Corte', 'liquidaciones_beneficiario.fecha_corte')
            ->estado('estado', 'Estado', 'liquidaciones_beneficiario.estado_id', LiquidacionBeneficiario::ETIQUETAS)
            ->numero('total', 'Total', 'liquidaciones_beneficiario.total')
            ->numero('saldo', 'Saldo', $saldo)
            ->persona('armada', 'Armada por', 'liquidaciones_beneficiario.armada_por', via: 'users');

        return $this->listado($request, 'admin.liquidaciones-beneficiarios', [
            'liquidaciones' => $grilla->aplicar(LiquidacionBeneficiario::query()
                ->when($beneficiario, fn ($query) => $query->delBeneficiario(...$beneficiario))
                ->when($estado, fn ($query) => $query->where('estado_id', Estado::idDe($estado)))
                ->when(ctype_digit($busqueda), fn ($query) => $query->whereKey((int) $busqueda))
                ->with(['profesional.persona', 'proveedor.persona', 'moneda', 'armadaPor.persona'])
                ->withSum('pagosVigentes', 'monto'), fn ($query) => $query->orderByDesc('fecha_corte')->orderByDesc('id'))
                ->paginate(20)->withQueryString(),
            'grilla' => $grilla,
            'busqueda' => $busqueda,
            'filtros' => ['beneficiario' => $beneficiario ? implode(':', $beneficiario) : null, 'estado' => $estado],
            'opcionesBeneficiario' => self::beneficiarios(),
            'opcionesEstado' => LiquidacionBeneficiario::ETIQUETAS,
            'pendientesDeAprobacion' => $request->user()->tienePermiso('APROBACION_LIQ_BENEFICIARIOS', 'VER') && ! $request->ajax()
                ? LiquidacionBeneficiario::where('estado_id', Estado::idDe(Estado::PENDIENTE_APROBACION))->count() : 0,
            'activa' => 'liquidaciones',
        ]);
    }

    /** Pestaña "Por pagar por beneficiario": lo aprobado, lo pagado, el saldo y el saldo en contra que pasa a la siguiente. */
    public function porPagar(): View
    {
        $aprobadas = LiquidacionBeneficiario::query()->aprobadas()->with(['profesional.persona', 'proveedor.persona', 'moneda'])->withSum('pagosVigentes', 'monto')
            ->orderBy('fecha_corte')->orderBy('id')->get();
        $porBeneficiario = $aprobadas->groupBy(fn (LiquidacionBeneficiario $l) => $l->beneficiario.':'.($l->profesional_id ?? $l->proveedor_id))
            ->map(fn ($liquidaciones, string $clave) => [
                'clave' => $clave,
                'beneficiario' => $liquidaciones->first()->beneficiarioTexto(),
                'tipo' => $liquidaciones->first()->tipoTexto(),
                'aprobado' => $liquidaciones->sum(fn (LiquidacionBeneficiario $l) => max(0, $l->totalEntero())),
                'pagado' => $liquidaciones->sum(fn (LiquidacionBeneficiario $l) => $l->pagado()),
                'saldo' => $liquidaciones->sum(fn (LiquidacionBeneficiario $l) => $l->saldo()),
                'enContra' => $liquidaciones->sum(fn (LiquidacionBeneficiario $l) => $l->saldoEnContra()),
                'conSaldo' => $liquidaciones->filter(fn (LiquidacionBeneficiario $l) => $l->saldo() > 0)->values(),
            ])->sortBy('beneficiario')->values();

        return view('admin.liquidaciones-beneficiarios.por-pagar', [
            'porBeneficiario' => $porBeneficiario,
            'moneda' => Moneda::guarani(),
            'activa' => 'por-pagar',
        ]);
    }

    /**
     * Pestaña "Pendientes de liquidar": por beneficiario, lo que ya se puede liquidar (percibido y libre) y lo que todavía no, con el
     * motivo (sin cobrar, aseguradora sin pagar o pendiente de reparto).
     */
    public function pendientes(Request $request): View
    {
        $beneficiario = self::beneficiarioDeTexto((string) $request->query('beneficiario'));
        $filtrar = fn ($query) => $beneficiario ? Elegibilidad::delBeneficiario($query, ...$beneficiario) : Elegibilidad::liquidables($query);

        $listas = $filtrar(Elegibilidad::libres(Elegibilidad::percibidas()))->with(['profesional.persona', 'proveedor.persona'])->get()
            ->groupBy(fn (LineaReparto $l) => ($l->beneficiario === LineaReparto::PROFESIONAL ? 'PROFESIONAL:'.$l->profesional_id : 'EQUIPO:'.$l->proveedor_id))
            ->map(fn ($lineas, string $clave) => ['clave' => $clave, 'beneficiario' => $lineas->first()->beneficiario === LineaReparto::PROFESIONAL
                ? (string) $lineas->first()->profesional?->persona?->nombre_completo : (string) $lineas->first()->proveedor?->persona?->nombre_completo,
                'tipo' => LineaReparto::ETIQUETAS[$lineas->first()->beneficiario], 'cantidad' => $lineas->count(),
                'total' => (int) $lineas->sum(fn (LineaReparto $l) => (int) round((float) $l->monto))])
            ->sortBy('beneficiario')->values();

        $percibidas = Elegibilidad::percibidas()->select('lineas_reparto.id');
        $noPercibidas = $filtrar(Elegibilidad::libres(Elegibilidad::devengadas()))->whereNotIn('lineas_reparto.id', $percibidas);
        $cantidad = (clone $noPercibidas)->count();
        $filas = $noPercibidas->with(['reparto.prestacion.procedimiento', 'reparto.prestacion.moneda', 'profesional.persona', 'proveedor.persona', 'equipo'])
            ->orderBy('p.fecha_servicio')->orderBy('p.id')->limit(self::PENDIENTES_MAXIMO)->get()
            ->map(fn (LineaReparto $linea) => ['linea' => $linea, 'prestacion' => $linea->reparto->prestacion,
                'motivo' => Elegibilidad::motivoNoPercibida($linea->reparto->prestacion) ?? '']);

        // Pendientes de reparto: sin líneas todavía (falta un acuerdo). Solo el profesional se conoce.
        $sinReparto = RepartoPrestacion::query()->where('estado_id', Estado::idDe(Estado::PENDIENTE))->whereNull('anulado_en')
            ->whereHas('prestacion', fn ($q) => $q->where('estado_id', '!=', Estado::idDe(Estado::ANULADO))
                ->when($beneficiario && $beneficiario[0] === LiquidacionBeneficiario::PROFESIONAL, fn ($q) => $q->where('profesional_id', $beneficiario[1])))
            ->when($beneficiario && $beneficiario[0] === LiquidacionBeneficiario::EQUIPO, fn ($q) => $q->whereRaw('1 = 0'));

        return view('admin.liquidaciones-beneficiarios.pendientes', [
            'listas' => $listas,
            'cantidad' => $cantidad,
            'filas' => $filas,
            'cantidadSinReparto' => (clone $sinReparto)->count(),
            'sinReparto' => $sinReparto->with(['prestacion.profesional.persona', 'prestacion.procedimiento'])->orderBy('id')->limit(self::PENDIENTES_MAXIMO)->get(),
            'filtros' => ['beneficiario' => $beneficiario ? implode(':', $beneficiario) : null],
            'opcionesBeneficiario' => self::beneficiarios(),
            'moneda' => Moneda::guarani(),
            'vePrestaciones' => Permisos::puedeRuta('admin.prestaciones.show'),
            'puedeArmar' => Permisos::puedeRuta('admin.liquidaciones-beneficiarios.create'),
            'activa' => 'pendientes',
        ]);
    }

    /** Pestaña "Resumen por período" (objetivo 14): por beneficiario, también la clínica. */
    public function resumen(Request $request): View
    {
        [$desdeTexto, $hastaTexto] = [trim((string) $request->query('desde', Fecha::mostrar(Fecha::hoy()->startOfMonth()))), trim((string) $request->query('hasta', Fecha::mostrar(Fecha::hoy())))];
        [$desde, $hasta] = [self::iso($desdeTexto), self::iso($hastaTexto)];
        $error = ($desde === null || $hasta === null) ? 'Escriba las fechas con el formato dd/mm/aaaa.' : ($desde > $hasta ? 'La fecha "desde" no puede ser posterior a "hasta".' : null);

        return view('admin.liquidaciones-beneficiarios.resumen', [
            'desdeTexto' => $desdeTexto, 'hastaTexto' => $hastaTexto, 'error' => $error,
            'filas' => $error ? collect() : ResumenBeneficiarios::periodo(Carbon::parse($desde), Carbon::parse($hasta)),
            'moneda' => Moneda::guarani(),
            'activa' => 'resumen',
        ]);
    }

    /** Armar, en una sola pantalla: beneficiario y corte; con los dos, la vista previa y "Confirmar". */
    public function create(Request $request): View
    {
        $opciones = self::beneficiarios();
        $texto = (string) $request->query('beneficiario');
        $beneficiario = self::beneficiarioDeTexto($texto);
        $corteTexto = trim((string) $request->query('corte', Fecha::mostrar(Fecha::hoy())));
        $error = null;
        $corte = null;
        if ($texto !== '' && ($beneficiario === null || ! array_key_exists($texto, $opciones))) {
            $error = ArmarLiquidacionBeneficiario::BENEFICIARIO_INVALIDO;
            $beneficiario = null;
        } elseif (($iso = self::iso($corteTexto)) === null) {
            $error = 'Escriba la fecha de corte con el formato dd/mm/aaaa.';
        } else {
            $corte = Carbon::parse($iso);
            try {
                ArmarLiquidacionBeneficiario::verificarCorte($corte);
            } catch (AccionRechazada $e) {
                [$error, $corte] = [$e->getMessage(), null];
            }
        }

        $vista = ['opciones' => $opciones, 'beneficiarioTexto' => $texto, 'corteTexto' => $corteTexto, 'error' => $texto !== '' ? $error : null,
            'moneda' => Moneda::guarani(), 'previa' => null, 'abierta' => null, 'deProfesional' => $beneficiario && $beneficiario[0] === LiquidacionBeneficiario::PROFESIONAL];
        if ($beneficiario !== null && $corte !== null) {
            $vista = [...$vista,
                'previa' => ArmarLiquidacionBeneficiario::vistaPrevia($beneficiario[0], $beneficiario[1], $corte),
                'abierta' => LiquidacionBeneficiario::query()->delBeneficiario(...$beneficiario)->abiertas()->first(),
            ];
        }

        return view('admin.liquidaciones-beneficiarios.create', $vista);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'beneficiario' => ['required', 'string'],
            'fecha_corte' => ['required', ...Fecha::regla()],
            'cantidad' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer'],
        ], Fecha::mensajes('fecha_corte'), ['fecha_corte' => 'fecha de corte']);

        $beneficiario = array_key_exists($datos['beneficiario'], self::beneficiarios()) ? self::beneficiarioDeTexto($datos['beneficiario']) : null;
        if ($beneficiario === null) {
            return back()->withInput()->with('error', ArmarLiquidacionBeneficiario::BENEFICIARIO_INVALIDO);
        }

        try {
            $liquidacion = ArmarLiquidacionBeneficiario::ejecutar($request->user(), $beneficiario[0], $beneficiario[1], Carbon::parse(Fecha::aIso($datos['fecha_corte'])),
                (int) $datos['cantidad'], (int) $datos['total']);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.liquidaciones-beneficiarios.show', $liquidacion)
            ->with('status', "Liquidación N.º {$liquidacion->id} armada: {$liquidacion->formatear($liquidacion->totalEntero())}. Revísela y envíela a aprobación.");
    }

    public function show(Request $request, LiquidacionBeneficiario $liquidacion): View
    {
        $liquidacion->load(['profesional.persona', 'proveedor.persona', 'moneda', 'armadaPor.persona', 'enviadaPor.persona', 'aprobadaPor.persona',
            'devueltaPor.persona', 'anuladaPor.persona', 'lineas.liberadaPor.persona', 'lineas.ajustaA', 'pagos.medioPago', 'pagos.usuario.persona',
            'pagos.anuladoPor.persona', 'pagos.caja', 'pagos.categoriaGasto', 'pagos.salida.caja', 'pagos.salida.solicitudPendiente']);
        $usuario = $request->user();
        $revisa = Permisos::puedeRuta('admin.liquidaciones-beneficiarios.aprobar');
        $propia = (int) $liquidacion->armada_por === (int) $usuario->id;
        $marcadas = FlujoLiquidacionBeneficiario::marcadas($liquidacion)->keyBy(fn (array $m) => $m['linea']->id)->map(fn (array $m) => $m['motivo']);
        $impedimento = FlujoLiquidacionBeneficiario::impedimentoParaAnular($liquidacion);

        return view('admin.liquidaciones-beneficiarios.show', [
            'liquidacion' => $liquidacion,
            'lineas' => self::ordenarLineas($liquidacion->lineas),
            'marcadas' => $marcadas,
            'puedeQuitar' => $liquidacion->enPreparacion() && Permisos::puedeRuta('admin.liquidaciones-beneficiarios.lineas.quitar'),
            'puedeEnviar' => $liquidacion->enPreparacion() && Permisos::puedeRuta('admin.liquidaciones-beneficiarios.enviar'),
            'puedeRevisar' => $liquidacion->pendienteDeAprobacion() && $revisa && ! $propia,
            'esPropia' => $liquidacion->pendienteDeAprobacion() && $revisa && $propia,
            'puedeAnular' => $impedimento === null && Permisos::puedeRuta('admin.liquidaciones-beneficiarios.anular') && (! $liquidacion->aprobada() || $revisa),
            'impedimentoAnular' => ! $liquidacion->anulada() ? $impedimento : null,
            'puedePlanilla' => ! $liquidacion->anulada() && Permisos::puedeRuta('admin.liquidaciones-beneficiarios.planilla'),
            'vePagos' => $usuario->tienePermiso('PAGOS_BENEFICIARIOS', 'VER'),
            'puedePagar' => $liquidacion->aprobada() && $liquidacion->saldo() > 0 && Permisos::puedeRuta('admin.liquidaciones-beneficiarios.pagos.store'),
            'puedeAnularPago' => Permisos::puedeRuta('admin.liquidaciones-beneficiarios.pagos.anular'),
            'medios' => MedioPago::activos()->orderBy('nombre')->get(),
            'medioSugerido' => MedioPago::where('codigo', 'TRANSFERENCIA')->activos()->value('id'),
            'cajas' => PagosBeneficiario::cajasDisponibles($usuario)->mapWithKeys(fn ($caja) => [$caja->id => "Caja N.º {$caja->id} ({$caja->sucursal?->nombre}), abierta hoy"])->all(),
            'categorias' => RegistrarSalida::categorias()->pluck('nombre', 'id')->all(),
            'vePrestaciones' => Permisos::puedeRuta('admin.prestaciones.show'),
            'veCajas' => Permisos::puedeRuta('admin.cajas.show'),
        ]);
    }

    public function quitarLinea(Request $request, LineaLiquidacionBeneficiario $linea): RedirectResponse
    {
        $motivo = $this->texto($request, 'motivo_quitar', 'motivo');

        try {
            FlujoLiquidacionBeneficiario::quitarLinea($request->user(), $linea, $motivo);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Línea quitada de la liquidación: queda para la siguiente.');
    }

    public function enviar(Request $request, LiquidacionBeneficiario $liquidacion): RedirectResponse
    {
        return $this->accion(fn () => FlujoLiquidacionBeneficiario::enviar($request->user(), $liquidacion), 'Liquidación enviada a aprobación.');
    }

    public function aprobar(Request $request, LiquidacionBeneficiario $liquidacion): RedirectResponse
    {
        return $this->accion(fn () => FlujoLiquidacionBeneficiario::aprobar($request->user(), $liquidacion), 'Liquidación aprobada: queda bloqueada y lista para pagar.');
    }

    public function devolver(Request $request, LiquidacionBeneficiario $liquidacion): RedirectResponse
    {
        $nota = $this->texto($request, 'nota_devolucion', 'nota');

        return $this->accion(fn () => FlujoLiquidacionBeneficiario::devolver($request->user(), $liquidacion, $nota), 'Liquidación devuelta: vuelve a estar en preparación.');
    }

    /** Anular: con motivo y sin pagos vigentes. Una aprobada, solo quien aprueba (Gerencia o el Administrador). */
    public function anular(Request $request, LiquidacionBeneficiario $liquidacion): RedirectResponse
    {
        $motivo = $this->texto($request, 'motivo_anulacion', 'motivo de la anulación');
        if ($liquidacion->aprobada() && ! $request->user()->tienePermiso('APROBACION_LIQ_BENEFICIARIOS', 'EDITAR')) {
            return back()->with('error', 'Una liquidación aprobada solo la anula quien aprueba las liquidaciones.');
        }

        return $this->accion(fn () => FlujoLiquidacionBeneficiario::anular($request->user(), $liquidacion, $motivo), 'Liquidación anulada: sus líneas quedan libres para la siguiente.');
    }

    public function planilla(LiquidacionBeneficiario $liquidacion): RespuestaHttp
    {
        try {
            return PlanillaLiquidacionBeneficiario::ejecutar($liquidacion);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function registrarPago(Request $request, LiquidacionBeneficiario $liquidacion): RedirectResponse
    {
        $datos = $request->validate([
            'monto' => Guaranies::regla(),
            'fecha' => ['required', ...Fecha::regla(hastaHoy: true)],
            'medio_pago_id' => ['required', 'integer'],
            'referencia' => ['nullable', 'string', 'max:'.PagosBeneficiario::REFERENCIA_MAXIMA],
            'caja_id' => ['nullable', 'integer'],
            'categoria_gasto_id' => ['nullable', 'integer'],
        ], ['monto.regex' => Guaranies::MENSAJE, ...Fecha::mensajes('fecha')], ['medio_pago_id' => 'medio de pago', 'referencia' => 'comprobante']);

        $cajaId = filled($datos['caja_id'] ?? null) ? (int) $datos['caja_id'] : null;
        if ($cajaId !== null && ! PagosBeneficiario::cajasDisponibles($request->user())->contains('id', $cajaId)) {
            return back()->withInput()->with('error', 'Elija su caja abierta de hoy, o ninguna.');
        }

        try {
            $pago = PagosBeneficiario::registrar($request->user(), $liquidacion, [
                'monto' => Guaranies::entero($datos['monto']), 'fecha' => Carbon::parse(Fecha::aIso($datos['fecha'])),
                'medio_pago_id' => (int) $datos['medio_pago_id'], 'referencia' => $datos['referencia'] ?? null,
                'caja_id' => $cajaId, 'categoria_gasto_id' => filled($datos['categoria_gasto_id'] ?? null) ? (int) $datos['categoria_gasto_id'] : null,
            ]);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $liquidacion = $pago->liquidacion;
        $caja = $pago->conCaja() ? " Salió de la caja N.º {$pago->caja_id}." : '';

        return back()->with('status', "Pago registrado: {$liquidacion->formatear($pago->montoEntero())}. Saldo: {$liquidacion->formatear($liquidacion->saldo())}.{$caja}");
    }

    public function anularPago(Request $request, PagoBeneficiario $pago): RedirectResponse
    {
        $motivo = $this->texto($request, 'motivo_pago', 'motivo de la anulación');

        try {
            $mensaje = PagosBeneficiario::anular($request->user(), $pago, $motivo);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $mensaje);
    }

    /** Las líneas en el orden de la planilla: ordinarias por fecha, después ajustes y saldos anteriores. */
    public static function ordenarLineas($lineas)
    {
        $orden = [LineaLiquidacionBeneficiario::ORDINARIA => 0, LineaLiquidacionBeneficiario::AJUSTE => 1, LineaLiquidacionBeneficiario::SALDO_ANTERIOR => 2];

        return $lineas->sortBy([fn ($a, $b) => $orden[$a->tipo] <=> $orden[$b->tipo], ['fecha_servicio', 'asc'], ['id', 'asc']])->values();
    }

    /**
     * Los beneficiarios que se pueden liquidar: los profesionales (activos o con líneas de reparto) y los proveedores dueños de algún
     * equipo. Clave "PROFESIONAL:id" o "EQUIPO:id" => "Profesional · nombre".
     */
    public static function beneficiarios(): array
    {
        $conLineas = LineaReparto::query()->select('profesional_id')->whereNotNull('profesional_id');
        $profesionales = Profesional::query()->where(fn ($q) => $q->activos()->orWhereIn('id', $conLineas))->with('persona')->get()
            ->mapWithKeys(fn (Profesional $p) => ['PROFESIONAL:'.$p->id => 'Profesional · '.$p->persona->nombre_completo])->sort();
        $duenios = Proveedor::query()->where(fn ($q) => $q->whereIn('id', Equipo::query()->select('proveedor_id'))
            ->orWhereIn('id', LineaReparto::query()->select('proveedor_id')->whereNotNull('proveedor_id')))->with('persona')->get()
            ->mapWithKeys(fn (Proveedor $p) => ['EQUIPO:'.$p->id => 'Dueño de equipo · '.$p->persona->nombre_completo])->sort();

        return [...$profesionales->all(), ...$duenios->all()];
    }

    /** "PROFESIONAL:5" => ['PROFESIONAL', 5]; cualquier otra cosa, null. */
    public static function beneficiarioDeTexto(string $texto): ?array
    {
        if (! preg_match('/^(PROFESIONAL|EQUIPO):(\d+)$/', $texto, $partes)) {
            return null;
        }

        return [$partes[1], (int) $partes[2]];
    }

    private function accion(\Closure $accion, string $exito): RedirectResponse
    {
        try {
            $accion();
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $exito);
    }

    private function texto(Request $request, string $campo, string $nombre): string
    {
        return $request->validate(
            [$campo => ['required', 'string', 'min:'.FlujoLiquidacionBeneficiario::MOTIVO_MINIMO, 'max:'.FlujoLiquidacionBeneficiario::MOTIVO_MAXIMO]],
            ["{$campo}.required" => "Indique el {$nombre}.", "{$campo}.min" => "Indique el {$nombre} (al menos ".FlujoLiquidacionBeneficiario::MOTIVO_MINIMO.' caracteres).'],
            [$campo => $nombre],
        )[$campo];
    }

    private static function iso(string $texto): ?string
    {
        if (! preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $texto)) {
            return null;
        }
        try {
            $fecha = Carbon::createFromFormat('!'.Fecha::FORMATO, $texto);
        } catch (\Throwable) {
            return null;
        }

        return $fecha && $fecha->format(Fecha::FORMATO) === $texto ? $fecha->format('Y-m-d') : null;
    }
}
