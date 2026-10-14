<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\NotaSalida;
use App\Models\SalidaSinAtencion;
use App\Support\Atencion\GuardarNotaSalida;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Retiros y rechazos de los turnos del profesional (pantalla Consulta): los ve y, una sola vez por cada uno, completa
 * la nota clínica. Es contenido clínico: VER sobre HISTORIA_CLINICA para leer (con registro de lectura), CREAR y ser
 * el profesional del turno para escribir (ConsultaPolicy::notaDeSalida). Recepción registra el hecho desde Turnos y
 * no entra acá. Ver docs/retiros.md.
 */
class SalidaController extends Controller
{
    public function index(Request $request): View
    {
        $profesional = $request->user()->profesional;
        abort_unless($profesional?->estaActivo(), 403, AtencionController::SOLO_PROFESIONALES);

        $salidas = SalidaSinAtencion::with(['turno.paciente.persona', 'nota'])
            ->whereHas('turno', fn ($query) => $query->where('profesional_id', $profesional->id))
            ->latest('id')->limit(100)->get();
        Auditoria::registrarLecturas('notas_salida', $salidas->pluck('nota.id')->filter()->all());

        return view('admin.atencion.salidas', [
            'salidas' => $salidas,
            'puedeEscribir' => Gate::allows('cerrarJornada', Consulta::class),
            'textoMaximo' => NotaSalida::TEXTO_MAXIMO,
        ]);
    }

    public function guardarNota(Request $request, SalidaSinAtencion $salida): RedirectResponse
    {
        $datos = $request->validate(
            ['texto' => ['required', 'string', 'min:'.NotaSalida::TEXTO_MINIMO, 'max:'.NotaSalida::TEXTO_MAXIMO]],
            ['texto.required' => 'Escriba la nota clínica.', 'texto.min' => 'La nota es demasiado corta.', 'texto.max' => 'La nota es demasiado larga.'],
            ['texto' => 'nota clínica'],
        );

        try {
            GuardarNotaSalida::ejecutar($request->user(), $salida, trim($datos['texto']));
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.atencion.salidas')->with('status', 'Nota clínica guardada. No se puede modificar.');
    }
}
