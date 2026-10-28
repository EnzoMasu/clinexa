<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Estado;
use App\Models\Factura;
use App\Models\MotivoAnulacionFactura;
use App\Models\SolicitudAnulacionFactura;
use App\Support\Facturacion\AnularFactura;
use App\Support\Facturacion\PdfFactura;
use App\Support\Fecha;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as RespuestaHttp;

/**
 * Facturas (docs/facturacion.md), un módulo con pestañas (facturas, solicitudes de anulación, timbrados, tasas de IVA y motivos). Acá:
 * el listado y el detalle (VER sobre FACTURAS: Caja, Gerencia, Facturación), el PDF (EXPORTAR), la anulación directa y la solicitud
 * (DESACTIVAR: Caja) y las solicitudes pendientes con su aprobación o rechazo (ANULACIONES_FACTURA: Gerencia). La factura se emite al
 * cobrar, desde Caja (CobroController). Sin contenido clínico.
 */
class FacturaController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $desde = $this->fecha($request->query('desde'));
        $hasta = $this->fecha($request->query('hasta'));
        $estado = array_key_exists((string) $request->query('estado'), Factura::ETIQUETAS) ? $request->query('estado') : null;

        return $this->listado($request, 'admin.facturas', [
            'facturas' => Factura::query()
                ->when($desde, fn ($query) => $query->where('emitida_en', '>=', Carbon::parse($desde, config('app.zona_horaria_local'))->startOfDay()->utc()))
                ->when($hasta, fn ($query) => $query->where('emitida_en', '<', Carbon::parse($hasta, config('app.zona_horaria_local'))->addDay()->startOfDay()->utc()))
                ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->whereLike('numero_completo', "%{$busqueda}%")
                    ->orWhereLike('cliente_nombre', "%{$busqueda}%")
                    ->orWhereLike('cliente_documento', "%{$busqueda}%")))
                ->when($estado, fn ($query) => $query->where('estado_id', Estado::idDe($estado)))
                ->orderByDesc('emitida_en')->orderByDesc('id')
                ->with(['moneda', 'emisor.persona'])
                ->paginate(20)->withQueryString(),
            'busqueda' => $busqueda,
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'estado' => $estado],
        ]);
    }

    public function show(Factura $factura): View
    {
        $factura->load(['detalles.prestacion', 'moneda', 'emisor.persona', 'caja', 'cobro.estado', 'anuladaPor.persona', 'motivoAnulacion',
            'reemplazaA', 'reemplazadaPor', 'timbrado', 'paciente',
            'solicitudesAnulacion' => fn ($query) => $query->orderByDesc('id'), 'solicitudesAnulacion.estado', 'solicitudesAnulacion.motivo',
            'solicitudesAnulacion.solicitante.persona', 'solicitudesAnulacion.revisadoPor.persona']);
        $usuario = auth()->user();
        $pendiente = $factura->solicitudesAnulacion->first(fn (SolicitudAnulacionFactura $solicitud) => $solicitud->pendiente());
        $libre = ! $factura->anulada() && $pendiente === null && ! $factura->tieneDocumentosVinculados();
        $directa = AnularFactura::esDirecta($usuario, $factura);

        return view('admin.facturas.show', [
            'factura' => $factura,
            'puedePdf' => Permisos::puedeRuta('admin.facturas.pdf'),
            'veCobro' => $factura->cobro !== null && Permisos::puedeRuta('admin.cobros.show'),
            'puedeAnular' => $libre && $directa && Permisos::puedeRuta('admin.facturas.anular'),
            'puedeSolicitar' => $libre && ! $directa && Permisos::puedeRuta('admin.facturas.solicitar-anulacion'),
            'limite' => AnularFactura::limite($factura),
            'motivos' => MotivoAnulacionFactura::activos()->orderBy('id')->get(),
            'pendiente' => $pendiente,
            'puedeRevisar' => $pendiente !== null && $pendiente->usuario_id !== $usuario->id && Permisos::puedeRuta('admin.facturas.solicitudes.aprobar'),
            'sinCajaSolicitante' => $pendiente !== null && $factura->cobro !== null && ! $factura->cobro->revertido()
                && ! Caja::query()->abiertaDe($pendiente->solicitante)->whereDate('fecha', Fecha::hoy()->format('Y-m-d'))->exists(),
            // "Cobrar de nuevo": la anulada sin reemplazo, para quien cobra (la nueva queda como "reemplaza a").
            'puedeReemplazar' => $factura->anulada() && $factura->reemplazadaPor === null && Permisos::puedeRuta('admin.cobros.create'),
        ]);
    }

    /** El PDF (al vuelo, también de una anulada, marcada). */
    public function pdf(Factura $factura): RespuestaHttp
    {
        return PdfFactura::ejecutar($factura);
    }

    public function anular(Request $request, Factura $factura): RedirectResponse
    {
        [$motivo, $detalle] = $this->motivo($request);

        try {
            AnularFactura::anular($request->user(), $factura, $motivo, $detalle);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.facturas.show', $factura)
            ->with('status', "Factura {$factura->numero_completo} anulada: su cobro se revirtió y se devolvieron {$factura->totalTexto()} desde su caja.");
    }

    public function solicitarAnulacion(Request $request, Factura $factura): RedirectResponse
    {
        [$motivo, $detalle] = $this->motivo($request);

        try {
            AnularFactura::solicitar($request->user(), $factura, $motivo, $detalle);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.facturas.show', $factura)->with('status', 'Solicitud de anulación enviada: queda pendiente de aprobación.');
    }

    /** Las solicitudes de anulación pendientes (Gerencia), las más antiguas primero, con "Aprobar" en la misma fila. */
    public function solicitudes(): View
    {
        return view('admin.facturas.solicitudes', [
            'solicitudes' => SolicitudAnulacionFactura::query()->pendientes()
                ->with(['solicitante.persona', 'motivo', 'factura.moneda', 'factura.emisor.persona'])
                ->orderBy('solicitada_en')->orderBy('id')->get(),
            'puedeRevisar' => Permisos::puedeRuta('admin.facturas.solicitudes.aprobar'),
        ]);
    }

    public function aprobarAnulacion(Request $request, SolicitudAnulacionFactura $solicitud): RedirectResponse
    {
        $nota = $request->validate(['nota' => ['nullable', 'string', 'max:'.AnularFactura::DETALLE_MAXIMO]], [], ['nota' => 'nota'])['nota'] ?? null;

        try {
            AnularFactura::aprobar($request->user(), $solicitud, $nota);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Anulación aprobada: la factura {$solicitud->factura->numero_completo} quedó anulada y su cobro, revertido.");
    }

    public function rechazarAnulacion(Request $request, SolicitudAnulacionFactura $solicitud): RedirectResponse
    {
        $nota = $request->validate(
            ['nota_rechazo' => ['required', 'string', 'min:'.AnularFactura::DETALLE_MINIMO, 'max:'.AnularFactura::DETALLE_MAXIMO]],
            ['nota_rechazo.required' => 'Indique por qué se rechaza.', 'nota_rechazo.min' => 'Indique por qué se rechaza (al menos '.AnularFactura::DETALLE_MINIMO.' caracteres).'],
            ['nota_rechazo' => 'nota'],
        )['nota_rechazo'];

        try {
            AnularFactura::rechazar($request->user(), $solicitud, $nota);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Solicitud de anulación rechazada.');
    }

    /** @return array{0: ?int, 1: ?string} */
    private function motivo(Request $request): array
    {
        $datos = $request->validate(
            ['motivo_anulacion_id' => ['required', 'integer'], 'detalle' => ['nullable', 'string', 'max:'.AnularFactura::DETALLE_MAXIMO]],
            ['motivo_anulacion_id.required' => 'Elija el motivo de la anulación.'],
            ['motivo_anulacion_id' => 'motivo', 'detalle' => 'detalle'],
        );

        return [(int) $datos['motivo_anulacion_id'], $datos['detalle'] ?? null];
    }

    /** Un filtro de fecha dd/mm/aaaa válido, en ISO; si no, null. */
    private function fecha(mixed $texto): ?string
    {
        $texto = (string) $texto;

        return preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) && Carbon::canBeCreatedFromFormat($texto, Fecha::FORMATO) ? Fecha::aIso($texto) : null;
    }
}
