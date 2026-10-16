<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Estudio;
use App\Models\OrdenEstudio;
use App\Support\OrdenEstudio\AnularOrden;
use App\Support\OrdenEstudio\EmitirOrden;
use App\Support\OrdenEstudio\PdfOrden;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Órdenes de estudio (docs/ordenes-estudio.md): la lista de una consulta con el formulario para emitir, el detalle, el PDF
 * (al vuelo) y la anulación con motivo. Quién puede lo decide OrdenEstudioPolicy. No hay edición ni borrado.
 */
class OrdenEstudioController extends Controller
{
    public function index(Consulta $consulta): View
    {
        abort_if($consulta->anulada(), 404);
        Gate::authorize('verDeConsulta', [OrdenEstudio::class, $consulta]);
        $consulta->load(['historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona']);
        $puedeEmitir = Gate::allows('emitir', [OrdenEstudio::class, $consulta]);

        return view('admin.ordenes-estudio.consulta', [
            'consulta' => $consulta,
            'ordenes' => OrdenEstudio::with('estado')->where('consulta_id', $consulta->id)->orderByDesc('id')->get(),
            'puedeEmitir' => $puedeEmitir,
            // Solo los activos: uno desactivado ya no se ofrece (las órdenes emitidas lo conservan en su snapshot).
            'catalogo' => $puedeEmitir ? Estudio::activos()->orderBy('nombre')->get() : collect(),
            'diagnosticos' => $puedeEmitir
                ? $consulta->diagnosticos()->with('cie10')->where('activo', true)->orderByDesc('principal')->orderBy('id')->get()
                : collect(),
        ]);
    }

    public function emitir(Request $request, Consulta $consulta): RedirectResponse
    {
        abort_if($consulta->anulada(), 404);
        Gate::authorize('emitir', [OrdenEstudio::class, $consulta]);

        $datos = $request->validate([
            'estudios' => ['nullable', 'array', 'max:'.EmitirOrden::MAXIMO_ESTUDIOS],
            'estudios.*' => ['integer', 'distinct'],
            'otros' => ['nullable', 'string', 'max:5000'],
            'indicacion_clinica' => ['nullable', 'string', 'max:'.EmitirOrden::MAXIMO_INDICACION],
            'diagnosticos' => ['nullable', 'array'],
            'diagnosticos.*' => ['integer', 'distinct'],
        ], [], [
            'estudios' => 'estudios del catálogo', 'otros' => 'otros estudios', 'indicacion_clinica' => 'indicación clínica',
        ]);

        // Otros estudios: uno por renglón, cada uno hasta el largo de un nombre del catálogo.
        $otros = preg_split('/\R/u', (string) ($datos['otros'] ?? ''));
        foreach ($otros as $otro) {
            if (mb_strlen(trim($otro)) > Estudio::MAXIMO_NOMBRE) {
                return back()->withInput()->withErrors(['otros' => 'Cada estudio escrito puede tener hasta '.Estudio::MAXIMO_NOMBRE.' caracteres.']);
            }
        }

        try {
            $orden = EmitirOrden::ejecutar($request->user(), $consulta, [
                'estudios' => $datos['estudios'] ?? [],
                'otros' => $otros,
                'indicacion_clinica' => $datos['indicacion_clinica'] ?? null,
                'diagnosticos' => $datos['diagnosticos'] ?? [],
            ]);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.ordenes-estudio.show', $orden)->with('status', "Orden de estudio {$orden->numero} emitida.");
    }

    public function show(OrdenEstudio $orden): View
    {
        $orden->load(['estado', 'consulta']);
        Gate::authorize('ver', $orden);

        return view('admin.ordenes-estudio.show', [
            'orden' => $orden,
            'hoja' => $orden->snapshot,
            'integra' => $orden->huellaValida(),
            'puedeAnular' => Gate::allows('anular', $orden),
            'puedeVerConsulta' => Gate::allows('verDeConsulta', [OrdenEstudio::class, $orden->consulta]),
        ]);
    }

    /** El PDF, solo si la huella coincide con el contenido: si no, avisa y no lo genera. */
    public function pdf(OrdenEstudio $orden): Response
    {
        $orden->load(['estado', 'consulta']);
        Gate::authorize('ver', $orden);
        if (! $orden->huellaValida()) {
            return redirect()->route('admin.ordenes-estudio.show', $orden)->with('error', 'La huella de esta orden no coincide con su contenido: no se genera el PDF.');
        }

        return PdfOrden::ejecutar($orden);
    }

    public function anular(Request $request, OrdenEstudio $orden): RedirectResponse
    {
        $orden->load('consulta');
        $motivo = $request->validate(
            ['motivo' => ['required', 'string', 'min:'.AnularOrden::MOTIVO_MINIMO, 'max:'.AnularOrden::MOTIVO_MAXIMO]],
            ['motivo.required' => 'Indique el motivo de la anulación.', 'motivo.min' => 'El motivo de la anulación debe tener al menos '.AnularOrden::MOTIVO_MINIMO.' caracteres.'],
            ['motivo' => 'motivo de la anulación'],
        )['motivo'];

        try {
            AnularOrden::ejecutar($orden, $motivo, fn (OrdenEstudio $actual) => Gate::authorize('anular', $actual));
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.ordenes-estudio.show', $orden)->with('status', "Orden de estudio {$orden->numero} anulada.");
    }
}
