<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Prestacion;
use App\Models\Profesional;
use App\Support\Fecha;
use App\Support\Permisos;
use App\Support\Prestaciones\AnularPrestacion;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Prestaciones\TarifarPrestacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Prestaciones (docs/tarifas-prestaciones.md): el listado con filtros (fecha de servicio desde y hasta, profesional, estado y
 * paciente), el detalle, "Tarifar" una pendiente y anular con motivo. Sin contenido clínico: no muestra nada de la consulta.
 */
class PrestacionController extends Controller
{
    /** Cuántas consultas sin prestación se listan (las más antiguas primero); la cantidad total se muestra igual. */
    public const SIN_PRESTACION_MAXIMO = 50;

    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $desde = $this->fecha($request->query('desde'));
        $hasta = $this->fecha($request->query('hasta'));
        $profesionalId = filter_var($request->query('profesional'), FILTER_VALIDATE_INT) ?: null;
        $estado = array_key_exists((string) $request->query('estado'), Prestacion::ETIQUETAS) ? (string) $request->query('estado') : null;

        $prestaciones = Prestacion::query()
            ->join('pacientes', 'pacientes.id', '=', 'prestaciones.paciente_id')
            ->join('personas', 'personas.id', '=', 'pacientes.persona_id')
            ->when($desde, fn ($query) => $query->whereDate('prestaciones.fecha_servicio', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('prestaciones.fecha_servicio', '<=', $hasta))
            ->when($profesionalId, fn ($query) => $query->where('prestaciones.profesional_id', $profesionalId))
            ->when($estado, fn ($query) => $query->where('prestaciones.estado_id', Estado::idDe($estado)))
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('personas.apellidos', "%{$busqueda}%")
                ->orWhereLike('personas.nombres', "%{$busqueda}%")
                ->orWhereLike('personas.nro_documento', "{$busqueda}%")
                ->orWhereLike('pacientes.nro_ficha', "%{$busqueda}%")))
            ->orderByDesc('prestaciones.fecha_servicio')->orderByDesc('prestaciones.id')
            ->select('prestaciones.*')
            ->with(['paciente.persona', 'profesional.persona', 'procedimiento', 'moneda'])
            ->paginate(20)->withQueryString();

        // "Consultas sin prestación" (solo las finalizadas desde el inicio del módulo): la cantidad la ve todo el que ve la
        // pantalla; la lista y "Generar", solo quien administra tarifas. No hace falta en la búsqueda en vivo (solo la tabla).
        $puedeGenerar = Permisos::puedeRuta('admin.prestaciones.generar');
        $sinPrestacion = $request->ajax() ? null : GenerarPrestacion::sinPrestacion();

        return $this->listado($request, 'admin.prestaciones', [
            'cantidadSinPrestacion' => $sinPrestacion?->count() ?? 0,
            'consultasSinPrestacion' => $sinPrestacion && $puedeGenerar
                ? $sinPrestacion->with(['historiaClinica.paciente.persona', 'profesional.persona'])->orderBy('finalizada_en')->limit(self::SIN_PRESTACION_MAXIMO)->get()
                : collect(),
            'puedeGenerar' => $puedeGenerar,
            'prestaciones' => $prestaciones,
            'busqueda' => $busqueda,
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'profesional' => $profesionalId, 'estado' => $estado],
            'opcionesProfesional' => Profesional::with('persona')->get()->sortBy('persona.nombre_completo')
                ->mapWithKeys(fn ($profesional) => [$profesional->id => $profesional->persona->nombre_completo])->all(),
            'opcionesEstado' => Prestacion::ETIQUETAS,
            'puedeTarifar' => Permisos::puedeRuta('admin.prestaciones.tarifar'),
        ]);
    }

    /** "Generar" la prestación de una consulta sin prestación, con el mismo servicio que al finalizar. */
    public function generar(Consulta $consulta): RedirectResponse
    {
        try {
            $prestacion = GenerarPrestacion::generar($consulta);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Prestación generada para la consulta N.º {$consulta->id}: ".($prestacion->pendiente() ? 'pendiente de tarifar.' : $prestacion->montoTexto().'.'));
    }

    public function show(Prestacion $prestacion): View
    {
        $prestacion->load(['paciente.persona', 'profesional.persona', 'procedimiento', 'moneda', 'tarifa', 'turno', 'tarifadaPor.persona', 'anuladaPor.persona']);

        return view('admin.prestaciones.show', [
            'prestacion' => $prestacion,
            'puedeTarifar' => $prestacion->pendiente() && Permisos::puedeRuta('admin.prestaciones.tarifar'),
            'puedeAnular' => ! $prestacion->anulada() && Permisos::puedeRuta('admin.prestaciones.anular'),
        ]);
    }

    public function tarifar(Request $request, Prestacion $prestacion): RedirectResponse
    {
        try {
            $prestacion = TarifarPrestacion::ejecutar($request->user(), $prestacion);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Prestación tarifada: {$prestacion->montoTexto()}.");
    }

    public function anular(Request $request, Prestacion $prestacion): RedirectResponse
    {
        $motivo = $request->validate(
            ['motivo' => ['required', 'string', 'min:'.AnularPrestacion::MOTIVO_MINIMO, 'max:'.AnularPrestacion::MOTIVO_MAXIMO]],
            ['motivo.required' => 'Indique el motivo de la anulación.', 'motivo.min' => 'El motivo de la anulación debe tener al menos '.AnularPrestacion::MOTIVO_MINIMO.' caracteres.'],
            ['motivo' => 'motivo de la anulación'],
        )['motivo'];

        try {
            AnularPrestacion::ejecutar($request->user(), $prestacion, $motivo);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.prestaciones.show', $prestacion)->with('status', 'Prestación anulada.');
    }

    /** Un filtro de fecha dd/mm/aaaa válido, en ISO; si no, null. */
    private function fecha(mixed $texto): ?string
    {
        $texto = (string) $texto;

        return preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) && Carbon::canBeCreatedFromFormat($texto, Fecha::FORMATO) ? Fecha::aIso($texto) : null;
    }
}
