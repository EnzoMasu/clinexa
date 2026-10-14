<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Constancia;
use App\Models\Turno;
use App\Support\Constancias\AnularConstancia;
use App\Support\Constancias\DatosConstancia;
use App\Support\Constancias\EmitirConstancia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Constancias de retiro y de estado del turno: emitir (desde un turno), imprimir y anular. Un mismo controlador para los dos
 * tipos; el tipo ("retiro" o "estado") viene como valor por defecto de la ruta, y cada ruta exige el permiso de su módulo.
 * Quién puede lo decide ConstanciaRetiroPolicy / ConstanciaEstadoPolicy. No hay edición ni borrado. Ver docs/constancias.md.
 */
class ConstanciaController extends Controller
{
    public function emitir(Request $request, Turno $turno): RedirectResponse
    {
        $modelo = $this->modelo($request);
        Gate::authorize('emitir', [$modelo, $turno]);

        try {
            [$constancia, $nueva] = EmitirConstancia::ejecutar($request->user(), $modelo, $turno);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($this->ruta($request, 'imprimir'), $constancia->id)
            ->with($nueva ? 'status' : 'aviso', $nueva ? "{$modelo::nombreDelTipo()} {$constancia->numero} emitida." : "Ya había una constancia emitida para este turno: {$constancia->numero}.")
            ->with('imprimir', $nueva);
    }

    /** La hoja de una constancia emitida o anulada, siempre desde su snapshot. */
    public function imprimir(Request $request, int $constancia): View
    {
        $modelo = $this->modelo($request);
        $constancia = $modelo::with('turno')->findOrFail($constancia);
        Gate::authorize('ver', $constancia);

        return view('constancias.hoja', [
            'constancia' => $constancia,
            'hoja' => $constancia->snapshot,
            'tipo' => $request->route('tipo'),
            'puedeAnular' => Gate::allows('anular', $constancia),
            'integra' => $constancia->huellaValida(),
        ]);
    }

    public function anular(Request $request, int $constancia): RedirectResponse
    {
        $modelo = $this->modelo($request);
        $constancia = $modelo::with('turno')->findOrFail($constancia);
        $motivo = $request->validate(
            ['motivo' => ['required', 'string', 'min:'.AnularConstancia::MOTIVO_MINIMO, 'max:'.AnularConstancia::MOTIVO_MAXIMO]],
            ['motivo.required' => 'Indique el motivo de la anulación.', 'motivo.min' => 'El motivo de la anulación debe tener al menos '.AnularConstancia::MOTIVO_MINIMO.' caracteres.'],
            ['motivo' => 'motivo de la anulación'],
        )['motivo'];

        try {
            AnularConstancia::ejecutar($constancia, $motivo, fn (Constancia $actual) => Gate::authorize('anular', $actual->load('turno')));
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($this->ruta($request, 'imprimir'), $constancia->id)->with('status', "{$modelo::nombreDelTipo()} {$constancia->numero} anulada.");
    }

    /** @return class-string<Constancia> */
    private function modelo(Request $request): string
    {
        return DatosConstancia::MODELOS[$request->route('tipo')] ?? abort(404);
    }

    private function ruta(Request $request, string $accion): string
    {
        return 'admin.constancias-'.$request->route('tipo').'.'.$accion;
    }
}
