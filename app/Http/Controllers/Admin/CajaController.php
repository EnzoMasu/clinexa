<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\MovimientoCaja;
use App\Models\SalidaCaja;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\AnularSalida;
use App\Support\Cobros\CerrarCaja;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\Guaranies;
use App\Support\Cobros\PdfArqueo;
use App\Support\Cobros\RegistrarSalida;
use App\Support\Cobros\RevisarCierre;
use App\Support\Fecha;
use App\Support\Grilla\Grilla;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Cajas (docs/cobros-caja.md): "Mi caja" (abrir, ver lo esperado y cerrar la propia) y el listado de cajas. El cajero ve las suyas;
 * quien tiene VER sobre CIERRES_CAJA (Gerencia) ve todas y acepta o rechaza los arqueos (EDITAR). La impresión del arqueo, en PDF. Recepción, Médico y Enfermería
 * no tienen el módulo.
 */
class CajaController extends Controller
{
    public function index(Request $request): Response
    {
        $usuario = $request->user();
        $veTodas = $usuario->tienePermiso('CIERRES_CAJA', 'VER');
        $estado = array_key_exists((string) $request->query('estado'), Caja::ETIQUETAS) ? (string) $request->query('estado') : null;
        $miCaja = Caja::query()->abiertaDe($usuario)->with(['moneda', 'estado'])->first();
        $grilla = Grilla::de($request)
            ->fecha('fecha', 'Fecha', 'cajas.fecha')
            ->persona('cajero', 'Cajero', 'cajas.usuario_id', via: 'users')
            ->numero('inicial', 'Monto inicial', 'cajas.monto_inicial')
            ->numero('cobros', 'Cobros', 'cobros_count', filtro: fn ($query, $n) => $query->has('cobros', '=', (int) $n))
            ->numero('cobrado', 'Total cobrado', 'cobros_sum_total',
                filtro: fn ($query, $n) => $query->whereRaw('COALESCE((SELECT SUM(c.total) FROM cobros c WHERE c.caja_id = cajas.id), 0) = ?', [$n]))
            ->estado('estado', 'Estado', 'cajas.estado_id', Caja::ETIQUETAS);

        return $this->listadoEn($request, 'admin.cajas.index', 'admin.cajas._tabla', [
            'grilla' => $grilla,
            'miCaja' => $miCaja,
            'miCajaDeOtroDia' => $miCaja && $miCaja->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d') ? Cobrar::deOtroDia($miCaja) : null,
            'devueltas' => Caja::where('usuario_id', $usuario->id)->where('estado_id', Estado::idDe(Estado::DEVUELTO))
                ->with(['ultimoCierre.revisadoPor.persona'])->orderBy('id')->get(),
            'puedeAbrir' => $miCaja === null && Permisos::puedeRuta('admin.cajas.abrir'),
            'sucursales' => AbrirCaja::sucursales(),
            'puedeCobrar' => $miCaja !== null && Permisos::puedeRuta('admin.cobros.create'),
            'puedeSalida' => $miCaja !== null && Permisos::puedeRuta('admin.cajas.salidas.store'),
            'veTodas' => $veTodas,
            'cantidadPendientes' => $veTodas ? Caja::where('estado_id', Estado::idDe(Estado::CERRADO))->count() : 0,
            'filtroEstado' => $estado,
            'opcionesEstado' => Caja::ETIQUETAS,
            'cajas' => $grilla->aplicar(Caja::query()
                ->unless($veTodas, fn ($query) => $query->where('usuario_id', $usuario->id))
                ->when($estado, fn ($query) => $query->where('estado_id', Estado::idDe($estado)))
                ->with(['cajero.persona', 'estado', 'moneda'])->withCount('cobros')->withSum('cobros', 'total'),
                fn ($query) => $query->orderByDesc('fecha')->orderByDesc('id'))
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function show(Request $request, Caja $caja): View
    {
        $usuario = $request->user();
        $this->autorizarVer($usuario, $caja);
        $caja->load(['cajero.persona', 'sucursal', 'estado', 'moneda', 'cierres' => fn ($query) => $query->orderByDesc('id'),
            'cierres.estado', 'cierres.medios.medioPago', 'cierres.cajero.persona', 'cierres.revisadoPor.persona']);
        $propia = $caja->usuario_id === $usuario->id;
        $puedeCerrar = $propia && ($caja->abierta() || $caja->devuelta()) && Permisos::puedeRuta('admin.cajas.cerrar');
        $esperado = $caja->esperadoPorMedio();

        return view('admin.cajas.show', [
            'caja' => $caja,
            'propia' => $propia,
            'esperado' => $esperado,
            'mediosADeclarar' => $puedeCerrar ? CerrarCaja::mediosADeclarar($caja) : collect(),
            'mediosConMovimientos' => MedioPago::whereKey(array_keys($esperado))->orderBy('nombre')->get(),
            'cobros' => $caja->cobros()->with(['paciente.persona', 'lineas.medioPago', 'moneda', 'estado'])->orderBy('id')->get(),
            // Los egresos de los cobros revertidos que se devolvieron desde esta caja (el cobro puede ser de otra).
            'devoluciones' => $caja->movimientos()->where('tipo', MovimientoCaja::REVERSION)->with(['cobro.paciente.persona', 'medioPago'])->orderBy('id')->get(),
            // Las salidas de dinero de esta caja (vigentes y anuladas) y el formulario para registrar otra (su cajero, con la caja abierta de hoy).
            'salidas' => $salidas = $caja->salidas()->with(['categoria', 'registradaPor.persona', 'anuladaPor.persona', 'solicitudPendiente', 'caja'])->orderBy('id')->get(),
            'puedeRegistrarSalida' => $puedeSalida = $propia && AnularSalida::cajaAbiertaDeHoy($caja) && Permisos::puedeRuta('admin.cajas.salidas.store'),
            'categoriasSalida' => $puedeSalida ? RegistrarSalida::categorias() : collect(),
            'anulablesDirecto' => $salidas->filter(fn (SalidaCaja $salida) => AnularSalida::esDirecta($usuario, $salida))->modelKeys(),
            'puedeAnularSalidas' => Permisos::puedeRuta('admin.salidas-caja.anular'),
            'plazoAnulacionSalida' => AnularSalida::plazoMinutos(),
            'puedeCerrar' => $puedeCerrar,
            'puedeRevisar' => ! $propia && $caja->cerrada() && Permisos::puedeRuta('admin.cajas.aprobar'),
            // En un arqueo devuelto, su cajero corrige el medio y el comprobante de los cobros desde el detalle de cada uno.
            'puedeCorregirCobros' => $propia && $caja->devuelta() && Permisos::puedeRuta('admin.cobros.corregir-comprobante'),
            'puedeImprimir' => $caja->cierres->isNotEmpty() && Permisos::puedeRuta('admin.cajas.arqueo'),
            'veCobros' => $usuario->tienePermiso('COBROS', 'VER'),
        ]);
    }

    public function abrir(Request $request): RedirectResponse
    {
        $datos = $request->validate(['monto_inicial' => Guaranies::regla(admiteCero: true), 'sucursal_id' => ['nullable', 'integer']],
            ['monto_inicial.regex' => Guaranies::MENSAJE], ['monto_inicial' => 'monto inicial', 'sucursal_id' => 'sucursal']);

        try {
            $caja = AbrirCaja::ejecutar($request->user(), Guaranies::entero($datos['monto_inicial']),
                isset($datos['sucursal_id']) ? (int) $datos['sucursal_id'] : null);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cajas.index')->with('status', "Caja abierta con {$caja->montoInicialTexto()} en efectivo.");
    }

    public function cerrar(Request $request, Caja $caja): RedirectResponse
    {
        $datos = $request->validate([
            'contado' => ['required', 'array'],
            // Con signo: en los medios que no son efectivo lo esperado puede ser negativo (CerrarCaja rechaza el efectivo negativo).
            'contado.*' => Guaranies::regla(admiteCero: true, conSigno: true),
            'motivo' => ['nullable', 'string', 'max:'.CerrarCaja::MOTIVO_MAXIMO],
        ], ['contado.*.regex' => Guaranies::MENSAJE, 'contado.*.required' => 'Declare lo contado (cero si no hay nada).'], ['contado.*' => 'lo contado', 'motivo' => 'motivo de la diferencia']);

        try {
            CerrarCaja::ejecutar($request->user(), $caja,
                collect($datos['contado'])->mapWithKeys(fn ($valor, $medio) => [(int) $medio => Guaranies::entero($valor)])->all(), $datos['motivo'] ?? null);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cajas.show', $caja)->with('status', 'Caja cerrada: queda pendiente de recepción.');
    }

    public function aprobar(Request $request, Caja $caja): RedirectResponse
    {
        $nota = $request->validate(['nota' => ['nullable', 'string', 'max:'.RevisarCierre::NOTA_MAXIMA]], [], ['nota' => 'nota'])['nota'] ?? null;

        try {
            RevisarCierre::aprobar($request->user(), $caja, $nota);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cajas.show', $caja)->with('status', 'Arqueo recibido.');
    }

    public function devolver(Request $request, Caja $caja): RedirectResponse
    {
        $nota = $request->validate(
            ['nota_devolucion' => ['required', 'string', 'min:'.RevisarCierre::NOTA_MINIMA, 'max:'.RevisarCierre::NOTA_MAXIMA]],
            ['nota_devolucion.required' => RevisarCierre::FALTA_MOTIVO, 'nota_devolucion.min' => RevisarCierre::FALTA_MOTIVO],
            ['nota_devolucion' => 'observación'],
        )['nota_devolucion'];

        try {
            RevisarCierre::devolver($request->user(), $caja, $nota);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cajas.show', $caja)->with('status', 'Arqueo devuelto por diferencias al cajero.');
    }

    /** La impresión del arqueo (PDF al vuelo, auditado): el cajero, la suya; Gerencia, cualquiera. */
    public function arqueo(Request $request, Caja $caja): SymfonyResponse
    {
        $this->autorizarVer($request->user(), $caja);

        try {
            return PdfArqueo::ejecutar($caja);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** El cajero ve las suyas; las de otros, solo quien ve todas (VER sobre CIERRES_CAJA). */
    private function autorizarVer(User $usuario, Caja $caja): void
    {
        abort_unless($caja->usuario_id === $usuario->id || $usuario->tienePermiso('CIERRES_CAJA', 'VER'), 403);
    }
}
