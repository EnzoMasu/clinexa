<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\AdendaConsulta;
use App\Models\Consulta;
use App\Models\DesbloqueoConsulta;
use App\Support\Atencion\DatosFormulario;
use App\Support\Atencion\FormularioConsulta;
use App\Support\Auditoria;
use App\Support\Correcciones\AgregarAdenda;
use App\Support\Correcciones\CorregirConsulta;
use App\Support\Correcciones\Desbloquear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Correcciones de una consulta FINALIZADA (docs/historia-clinica.md): agregar una adenda, abrir y cancelar un
 * desbloqueo y corregir con él (un solo guardado). Todo con motivo obligatorio. Quién puede cada cosa lo decide
 * ConsultaPolicy (agregarAdenda, desbloquear) y los servicios de App\Support\Correcciones lo revalidan adentro.
 * Sin JavaScript funcionan los formularios de adenda y de desbloqueo; el de corrección usa los mismos
 * paneles de la consulta (con JavaScript).
 */
class CorreccionController extends Controller
{
    /** Claves del pedido de corrección que se aceptan; cualquier otra (signos, triaje, recetas, paciente...) se rechaza. */
    private const CLAVES_PERMITIDAS = ['_token', '_method', 'motivo_consulta', 'con_anamnesis', 'anamnesis', 'examen',
        'con_diagnosticos', 'diagnosticos', 'diagnostico_principal', 'con_indicaciones', 'indicaciones', 'con_informes', 'informes'];

    public const SOLO_CORREGIBLE = 'Durante una corrección solo se pueden modificar el motivo, la anamnesis, los hallazgos, los diagnósticos, las indicaciones y los informes de estudio.';

    public function crearAdenda(Consulta $consulta): View
    {
        abort_if($consulta->anulada(), 404);
        Gate::authorize('agregarAdenda', $consulta);

        return view('admin.correcciones.adenda', ['consulta' => $consulta->load('historiaClinica.paciente.persona', 'profesional.persona')]);
    }

    public function guardarAdenda(Request $request, Consulta $consulta): RedirectResponse
    {
        abort_if($consulta->anulada(), 404);
        Gate::authorize('agregarAdenda', $consulta);
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:'.AdendaConsulta::MOTIVO_MINIMO, 'max:'.AdendaConsulta::MOTIVO_MAXIMO],
            'texto' => ['required', 'string', 'max:'.AdendaConsulta::TEXTO_MAXIMO],
        ], [
            'motivo.required' => 'Escriba el motivo de la adenda.',
            'motivo.min' => 'El motivo debe tener al menos '.AdendaConsulta::MOTIVO_MINIMO.' caracteres.',
            'texto.required' => 'Escriba el texto de la adenda.',
        ], ['motivo' => 'motivo', 'texto' => 'texto']);

        try {
            AgregarAdenda::ejecutar($request->user(), $consulta, $datos['motivo'], $datos['texto']);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.consultas.show', $consulta)->with('status', 'Adenda agregada.');
    }

    public function crearDesbloqueo(Consulta $consulta): View
    {
        abort_if($consulta->anulada(), 404);
        Gate::authorize('desbloquear', $consulta);

        return view('admin.correcciones.desbloqueo', ['consulta' => $consulta->load('historiaClinica.paciente.persona', 'profesional.persona')]);
    }

    public function desbloquear(Request $request, Consulta $consulta): RedirectResponse
    {
        abort_if($consulta->anulada(), 404);
        Gate::authorize('desbloquear', $consulta);
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:'.DesbloqueoConsulta::MOTIVO_MINIMO, 'max:'.DesbloqueoConsulta::MOTIVO_MAXIMO],
        ], [
            'motivo.required' => 'Escriba el motivo del desbloqueo.',
            'motivo.min' => 'El motivo debe tener al menos '.DesbloqueoConsulta::MOTIVO_MINIMO.' caracteres.',
        ], ['motivo' => 'motivo']);

        try {
            $desbloqueo = Desbloquear::abrir($request->user(), $consulta, $datos['motivo']);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.correcciones.corregir', $desbloqueo)->with('status', 'Consulta desbloqueada por '.DesbloqueoConsulta::HORAS_DE_VIGENCIA.' horas, para un solo guardado.');
    }

    public function cancelar(Request $request, DesbloqueoConsulta $desbloqueo): RedirectResponse
    {
        try {
            Desbloquear::cancelar($request->user(), $desbloqueo);
        } catch (AccionRechazada $e) {
            return redirect()->route('admin.consultas.show', $desbloqueo->consulta_id)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.consultas.show', $desbloqueo->consulta_id)->with('status', 'Desbloqueo cancelado.');
    }

    /** El formulario de corrección: solo con el desbloqueo vigente. */
    public function corregir(DesbloqueoConsulta $desbloqueo): View|RedirectResponse
    {
        DesbloqueoConsulta::vencerLosVencidos($desbloqueo->consulta_id);
        $desbloqueo->refresh();
        $consulta = $desbloqueo->consulta;
        Gate::authorize('desbloquear', $consulta);
        if (! $desbloqueo->estaVigente()) {
            return redirect()->route('admin.consultas.show', $consulta)->with('error', CorregirConsulta::NO_VIGENTE);
        }

        $consulta->load(['historiaClinica.paciente.persona.tipoDocumento', 'profesional.persona', ...DatosFormulario::RELACIONES]);
        Auditoria::registrarLectura('HISTORIA_CLINICA', $consulta->id, 'consultas', 'Corrección: formulario abierto');

        return view('admin.correcciones.corregir', ['consulta' => $consulta, 'desbloqueo' => $desbloqueo, 'datos' => DatosFormulario::de($consulta)]);
    }

    public function guardarCorreccion(Request $request, DesbloqueoConsulta $desbloqueo): RedirectResponse
    {
        $consulta = $desbloqueo->consulta;
        Gate::authorize('desbloquear', $consulta);

        // La lista blanca: un pedido con cualquier otro campo (signos vitales, triaje, receta, paciente, fechas, estado...) se rechaza entero.
        $extra = array_diff(array_keys($request->all()), self::CLAVES_PERMITIDAS);
        $examen = $request->input('examen');
        if ($extra !== [] || (is_array($examen) && array_diff(array_keys($examen), ['hallazgos']) !== [])) {
            throw ValidationException::withMessages(['correccion' => self::SOLO_CORREGIBLE]);
        }

        [$datos] = FormularioConsulta::validar($request, $consulta, CorregirConsulta::GRUPOS, final: true);

        try {
            CorregirConsulta::ejecutar($request->user(), $desbloqueo, $datos);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.consultas.show', $consulta)->with('status', 'Corrección guardada. La consulta volvió a quedar cerrada.');
    }
}
