<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Estado;
use App\Models\MedioPago;
use App\Models\User;
use App\Support\Cobros\AbrirCaja;
use App\Support\Cobros\CerrarCaja;
use App\Support\Cobros\Cobrar;
use App\Support\Cobros\Guaranies;
use App\Support\Cobros\RevisarCierre;
use App\Support\Fecha;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cajas (docs/cobros-caja.md): "Mi caja" (abrir, ver lo esperado y cerrar la propia) y el listado de cajas. El cajero ve las suyas;
 * quien tiene VER sobre CIERRES_CAJA (Gerencia) ve todas y aprueba o devuelve los cierres (EDITAR). Recepción, Médico y Enfermería
 * no tienen el módulo.
 */
class CajaController extends Controller
{
    public function index(Request $request): View
    {
        $usuario = $request->user();
        $veTodas = $usuario->tienePermiso('CIERRES_CAJA', 'VER');
        $estado = array_key_exists((string) $request->query('estado'), Caja::ETIQUETAS) ? (string) $request->query('estado') : null;
        $miCaja = Caja::query()->abiertaDe($usuario)->with(['moneda', 'estado'])->first();

        return view('admin.cajas.index', [
            'miCaja' => $miCaja,
            'miCajaDeOtroDia' => $miCaja && $miCaja->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d') ? Cobrar::deOtroDia($miCaja) : null,
            'devueltas' => Caja::where('usuario_id', $usuario->id)->where('estado_id', Estado::idDe(Estado::DEVUELTO))
                ->with(['ultimoCierre.revisadoPor.persona'])->orderBy('id')->get(),
            'puedeAbrir' => $miCaja === null && Permisos::puedeRuta('admin.cajas.abrir'),
            'puedeCobrar' => $miCaja !== null && Permisos::puedeRuta('admin.cobros.create'),
            'veTodas' => $veTodas,
            'cantidadPendientes' => $veTodas ? Caja::where('estado_id', Estado::idDe(Estado::CERRADO))->count() : 0,
            'filtroEstado' => $estado,
            'opcionesEstado' => Caja::ETIQUETAS,
            'cajas' => Caja::query()
                ->unless($veTodas, fn ($query) => $query->where('usuario_id', $usuario->id))
                ->when($estado, fn ($query) => $query->where('estado_id', Estado::idDe($estado)))
                ->with(['cajero.persona', 'estado', 'moneda'])->withCount('cobros')->withSum('cobros', 'total')
                ->orderByDesc('fecha')->orderByDesc('id')
                ->paginate(20)->withQueryString(),
        ]);
    }

    public function show(Request $request, Caja $caja): View
    {
        $usuario = $request->user();
        $this->autorizarVer($usuario, $caja);
        $caja->load(['cajero.persona', 'estado', 'moneda', 'cierres' => fn ($query) => $query->orderByDesc('id'),
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
            'cobros' => $caja->cobros()->with(['paciente.persona', 'lineas.medioPago', 'moneda'])->orderBy('id')->get(),
            'puedeCerrar' => $puedeCerrar,
            'puedeRevisar' => ! $propia && $caja->cerrada() && Permisos::puedeRuta('admin.cajas.aprobar'),
            'veCobros' => $usuario->tienePermiso('COBROS', 'VER'),
        ]);
    }

    public function abrir(Request $request): RedirectResponse
    {
        $datos = $request->validate(['monto_inicial' => Guaranies::regla(admiteCero: true)],
            ['monto_inicial.regex' => Guaranies::MENSAJE], ['monto_inicial' => 'monto inicial']);

        try {
            $caja = AbrirCaja::ejecutar($request->user(), Guaranies::entero($datos['monto_inicial']));
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cajas.index')->with('status', "Caja abierta con {$caja->montoInicialTexto()} en efectivo.");
    }

    public function cerrar(Request $request, Caja $caja): RedirectResponse
    {
        $datos = $request->validate([
            'contado' => ['required', 'array'],
            'contado.*' => Guaranies::regla(admiteCero: true),
            'motivo' => ['nullable', 'string', 'max:'.CerrarCaja::MOTIVO_MAXIMO],
        ], ['contado.*.regex' => Guaranies::MENSAJE, 'contado.*.required' => 'Declare lo contado (cero si no hay nada).'], ['contado.*' => 'lo contado', 'motivo' => 'motivo de la diferencia']);

        try {
            CerrarCaja::ejecutar($request->user(), $caja,
                collect($datos['contado'])->mapWithKeys(fn ($valor, $medio) => [(int) $medio => Guaranies::entero($valor)])->all(), $datos['motivo'] ?? null);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cajas.show', $caja)->with('status', 'Caja cerrada: queda pendiente de aprobación.');
    }

    public function aprobar(Request $request, Caja $caja): RedirectResponse
    {
        $nota = $request->validate(['nota' => ['nullable', 'string', 'max:'.RevisarCierre::NOTA_MAXIMA]], [], ['nota' => 'nota'])['nota'] ?? null;

        try {
            RevisarCierre::aprobar($request->user(), $caja, $nota);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cajas.show', $caja)->with('status', 'Cierre aprobado.');
    }

    public function devolver(Request $request, Caja $caja): RedirectResponse
    {
        $nota = $request->validate(
            ['nota_devolucion' => ['required', 'string', 'min:'.RevisarCierre::NOTA_MINIMA, 'max:'.RevisarCierre::NOTA_MAXIMA]],
            ['nota_devolucion.required' => 'Indique qué hay que corregir.', 'nota_devolucion.min' => 'Indique qué hay que corregir (al menos '.RevisarCierre::NOTA_MINIMA.' caracteres).'],
            ['nota_devolucion' => 'nota'],
        )['nota_devolucion'];

        try {
            RevisarCierre::devolver($request->user(), $caja, $nota);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.cajas.show', $caja)->with('status', 'Cierre devuelto al cajero para corregir.');
    }

    /** El cajero ve las suyas; las de otros, solo quien ve todas (VER sobre CIERRES_CAJA). */
    private function autorizarVer(User $usuario, Caja $caja): void
    {
        abort_unless($caja->usuario_id === $usuario->id || $usuario->tienePermiso('CIERRES_CAJA', 'VER'), 403);
    }
}
