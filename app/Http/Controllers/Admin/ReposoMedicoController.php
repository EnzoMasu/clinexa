<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\ReposoMedico;
use App\Support\Fecha;
use App\Support\Reposo\AnularReposo;
use App\Support\Reposo\EmitirReposo;
use App\Support\Reposo\PdfReposo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Certificados de reposo médico (docs/reposo-medico.md): la lista de una consulta con el formulario para emitir, el
 * detalle, el PDF (al vuelo) y la anulación con motivo. Quién puede lo decide ReposoMedicoPolicy. No hay edición ni borrado.
 */
class ReposoMedicoController extends Controller
{
    /** Días máximos admitidos: no hay tope clínico; solo evita fechas fuera de rango. */
    public const DIAS_MAXIMO = 36500;

    public function index(Request $request, Consulta $consulta): View
    {
        abort_if($consulta->anulada(), 404);
        Gate::authorize('verDeConsulta', [ReposoMedico::class, $consulta]);
        $consulta->load(['historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona']);
        $puedeEmitir = Gate::allows('emitir', [ReposoMedico::class, $consulta]);

        return view('admin.reposos.consulta', [
            'consulta' => $consulta,
            'reposos' => ReposoMedico::with('estado')->where('consulta_id', $consulta->id)->orderByDesc('id')->get(),
            'puedeEmitir' => $puedeEmitir,
            'diagnosticos' => $puedeEmitir
                ? $consulta->diagnosticos()->with('cie10')->where('activo', true)->orderByDesc('principal')->orderBy('id')->get()
                : collect(),
            // Para la prórroga: los certificados EMITIDOS del mismo paciente.
            'anteriores' => $puedeEmitir
                ? ReposoMedico::whereHas('consulta', fn ($query) => $query->where('historia_clinica_id', $consulta->historia_clinica_id))
                    ->where('estado_id', Estado::idDe(Estado::EMITIDO))->orderByDesc('id')->get()
                : collect(),
        ]);
    }

    public function emitir(Request $request, Consulta $consulta): RedirectResponse
    {
        abort_if($consulta->anulada(), 404);
        Gate::authorize('emitir', [ReposoMedico::class, $consulta]);

        $datos = $request->validate([
            'dias' => ['required', 'integer', 'min:1', 'max:'.self::DIAS_MAXIMO],
            'desde' => ['required', ...Fecha::regla(), 'after_or_equal:'.Fecha::hoy()->format(Fecha::FORMATO)],
            'modalidad' => ['required', Rule::in(array_keys(config('reposo.modalidades')))],
            'modalidad_otra' => ['nullable', 'required_if:modalidad,OTRA', 'string', 'max:300'],
            'empleador' => ['nullable', 'string', 'max:200'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'diagnosticos' => ['nullable', 'array'],
            'diagnosticos.*' => ['integer', 'distinct'],
            'prorroga_de' => ['nullable', 'string', 'max:20'],
        ], [
            ...Fecha::mensajes('desde'),
            'desde.after_or_equal' => 'La fecha "desde" no puede ser anterior a hoy.',
            'dias.min' => 'Los días de reposo tienen que ser al menos 1.',
            'dias.integer' => 'Los días de reposo tienen que ser un número entero.',
            'modalidad_otra.required_if' => 'Escriba la otra indicación.',
        ], [
            'dias' => 'días de reposo', 'desde' => 'desde', 'modalidad' => 'modalidad', 'modalidad_otra' => 'otra indicación',
            'empleador' => 'empleador o institución', 'observaciones' => 'observaciones y recomendaciones', 'prorroga_de' => 'prórroga de',
        ]);

        try {
            $reposo = EmitirReposo::ejecutar($request->user(), $consulta, [
                ...$datos,
                'dias' => (int) $datos['dias'],
                'desde' => Carbon::createFromFormat('!'.Fecha::FORMATO, $datos['desde'], config('app.zona_horaria_local')),
                'diagnosticos' => $datos['diagnosticos'] ?? [],
            ]);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.reposos.show', $reposo)->with('status', "Certificado de reposo {$reposo->numero} emitido.");
    }

    public function show(ReposoMedico $reposo): View
    {
        $reposo->load(['estado', 'consulta']);
        Gate::authorize('ver', $reposo);

        return view('admin.reposos.show', [
            'reposo' => $reposo,
            'hoja' => $reposo->snapshot,
            'integro' => $reposo->huellaValida(),
            'puedeAnular' => Gate::allows('anular', $reposo),
            'puedeVerConsulta' => Gate::allows('verDeConsulta', [ReposoMedico::class, $reposo->consulta]),
        ]);
    }

    /** El PDF, solo si la huella coincide con el contenido: si no, avisa y no lo genera. */
    public function pdf(ReposoMedico $reposo): Response
    {
        $reposo->load(['estado', 'consulta']);
        Gate::authorize('ver', $reposo);
        if (! $reposo->huellaValida()) {
            return redirect()->route('admin.reposos.show', $reposo)->with('error', 'La huella de este certificado no coincide con su contenido: no se genera el PDF.');
        }

        return PdfReposo::ejecutar($reposo);
    }

    public function anular(Request $request, ReposoMedico $reposo): RedirectResponse
    {
        $reposo->load('consulta');
        $motivo = $request->validate(
            ['motivo' => ['required', 'string', 'min:'.AnularReposo::MOTIVO_MINIMO, 'max:'.AnularReposo::MOTIVO_MAXIMO]],
            ['motivo.required' => 'Indique el motivo de la anulación.', 'motivo.min' => 'El motivo de la anulación debe tener al menos '.AnularReposo::MOTIVO_MINIMO.' caracteres.'],
            ['motivo' => 'motivo de la anulación'],
        )['motivo'];

        try {
            AnularReposo::ejecutar($reposo, $motivo, fn (ReposoMedico $actual) => Gate::authorize('anular', $actual));
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.reposos.show', $reposo)->with('status', "Certificado de reposo {$reposo->numero} anulado.");
    }
}
