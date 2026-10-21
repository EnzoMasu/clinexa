<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\InformeEstudio;
use App\Models\Prestacion;
use App\Models\Profesional;
use App\Support\Fecha;
use App\Support\Permisos;
use App\Support\Prestaciones\AnularPrestacion;
use App\Support\Prestaciones\GenerarPrestacion;
use App\Support\Prestaciones\TarifarPrestacion;
use App\Support\Repartos\RepartirPrestacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Prestaciones (docs/tarifas-prestaciones.md): el listado con filtros (fecha de servicio desde y hasta, profesional, estado, origen
 * y paciente), el detalle, "Tarifar" una pendiente y anular con motivo. Sin contenido clínico: no muestra nada de la consulta ni
 * del informe de estudio (solo que la prestación viene de uno).
 */
class PrestacionController extends Controller
{
    /** Cuántas consultas e informes sin prestación se listan (los más antiguos primero); la cantidad total se muestra igual. */
    public const SIN_PRESTACION_MAXIMO = 50;

    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $desde = $this->fecha($request->query('desde'));
        $hasta = $this->fecha($request->query('hasta'));
        $profesionalId = filter_var($request->query('profesional'), FILTER_VALIDATE_INT) ?: null;
        $estado = array_key_exists((string) $request->query('estado'), Prestacion::ETIQUETAS) ? (string) $request->query('estado') : null;
        $origen = array_key_exists((string) $request->query('origen'), Prestacion::ORIGENES) ? (string) $request->query('origen') : null;
        // El reparto (docs/reparto-economico.md) solo para quien ve los repartos: Recepción y Caja no ven porcentajes ni repartos.
        $veRepartos = $request->user()->tienePermiso('REPARTOS', 'VER');
        $soloPendientesDeReparto = $veRepartos && $request->query('reparto') === 'PENDIENTE';

        $prestaciones = ($soloPendientesDeReparto ? RepartirPrestacion::pendientes() : Prestacion::query())
            ->join('pacientes', 'pacientes.id', '=', 'prestaciones.paciente_id')
            ->join('personas', 'personas.id', '=', 'pacientes.persona_id')
            ->when($desde, fn ($query) => $query->whereDate('prestaciones.fecha_servicio', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('prestaciones.fecha_servicio', '<=', $hasta))
            ->when($profesionalId, fn ($query) => $query->where('prestaciones.profesional_id', $profesionalId))
            ->when($estado, fn ($query) => $query->where('prestaciones.estado_id', Estado::idDe($estado)))
            ->when($origen === 'INFORME', fn ($query) => $query->whereNotNull('prestaciones.informe_estudio_id'))
            ->when($origen === 'CONSULTA', fn ($query) => $query->whereNull('prestaciones.informe_estudio_id'))
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('personas.apellidos', "%{$busqueda}%")
                ->orWhereLike('personas.nombres', "%{$busqueda}%")
                ->orWhereLike('personas.nro_documento', "{$busqueda}%")
                ->orWhereLike('pacientes.nro_ficha', "%{$busqueda}%")))
            ->orderByDesc('prestaciones.fecha_servicio')->orderByDesc('prestaciones.id')
            ->select('prestaciones.*')
            ->with(['paciente.persona', 'profesional.persona', 'procedimiento', 'moneda'])
            // El estado de cobro (Cobrada / Pendiente), derivado de si tiene un cobro vigente (docs/cobros-caja.md).
            ->withExists('cobroVigente')
            ->paginate(20)->withQueryString();

        // "Consultas sin prestación" (solo lo finalizado desde el inicio del módulo): las consultas sin la base y los informes de
        // estudio sin la suya. La cantidad la ve todo el que ve la pantalla; la lista y "Generar", solo quien administra tarifas. No
        // hace falta en la búsqueda en vivo (solo la tabla).
        $puedeGenerar = Permisos::puedeRuta('admin.prestaciones.generar');
        $sinPrestacion = $request->ajax() ? null : GenerarPrestacion::sinPrestacion();
        $informesSinPrestacion = $request->ajax() ? null : GenerarPrestacion::informesSinPrestacion();
        $puedeRepartir = Permisos::puedeRuta('admin.prestaciones.repartir');

        return $this->listado($request, 'admin.prestaciones', [
            'veRepartos' => $veRepartos,
            'cantidadPendientesDeReparto' => $veRepartos && ! $request->ajax() ? RepartirPrestacion::pendientes()->count() : 0,
            'pendientesDeReparto' => $veRepartos && $puedeRepartir
                ? RepartirPrestacion::pendientes()->whereIn('prestaciones.id', $prestaciones->getCollection()->modelKeys())->pluck('prestaciones.id')->flip()->all()
                : [],
            'puedeRepartir' => $puedeRepartir,
            'cantidadSinPrestacion' => $sinPrestacion?->count() ?? 0,
            'cantidadInformesSinPrestacion' => $informesSinPrestacion?->count() ?? 0,
            'pendientesDeGenerar' => $sinPrestacion && $puedeGenerar ? $this->pendientesDeGenerar($sinPrestacion, $informesSinPrestacion) : collect(),
            'puedeGenerar' => $puedeGenerar,
            'prestaciones' => $prestaciones,
            'busqueda' => $busqueda,
            'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'profesional' => $profesionalId, 'estado' => $estado, 'origen' => $origen, 'reparto' => $soloPendientesDeReparto ? 'PENDIENTE' : null],
            'opcionesProfesional' => Profesional::with('persona')->get()->sortBy('persona.nombre_completo')
                ->mapWithKeys(fn ($profesional) => [$profesional->id => $profesional->persona->nombre_completo])->all(),
            'opcionesEstado' => Prestacion::ETIQUETAS,
            'opcionesOrigen' => Prestacion::ORIGENES,
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

    /** "Generar" la prestación de un informe de estudio que no la tiene (falló al finalizar). Dos veces no duplica. */
    public function generarInforme(InformeEstudio $informe): RedirectResponse
    {
        try {
            $prestacion = GenerarPrestacion::generarInforme($informe);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Prestación generada para el informe de estudio de la consulta N.º {$informe->consulta_id}: ".($prestacion->pendiente() ? 'pendiente de tarifar.' : $prestacion->montoTexto().'.'));
    }

    public function show(Request $request, Prestacion $prestacion): View
    {
        $prestacion->load(['paciente.persona', 'profesional.persona', 'procedimiento', 'moneda', 'tarifa', 'turno', 'tarifadaPor.persona', 'anuladaPor.persona',
            'cobroVigente.cobro']);
        // El detalle del reparto, solo con VER sobre REPARTOS (Administrador y Gerencia).
        $veRepartos = $request->user()->tienePermiso('REPARTOS', 'VER');
        if ($veRepartos) {
            $prestacion->load(['reparto.lineas.profesional.persona', 'reparto.lineas.proveedor.persona', 'reparto.lineas.equipo',
                'reparto.lineas.acuerdoProfesional.procedimiento', 'reparto.lineas.acuerdoEquipo', 'reparto.moneda', 'reparto.repartidoPor.persona', 'reparto.anuladoPor.persona']);
        }

        return view('admin.prestaciones.show', [
            'prestacion' => $prestacion,
            // Las demás de la misma consulta (la base y las de sus informes de estudio).
            'otrasDeLaConsulta' => Prestacion::where('consulta_id', $prestacion->consulta_id)->whereKeyNot($prestacion->id)
                ->with(['procedimiento', 'moneda'])->withExists('cobroVigente')->orderBy('id')->get(),
            // El cobro, con su enlace solo para quien ve los cobros (Recepción ve el estado, no el cobro).
            'cobro' => $prestacion->cobroVigente?->cobro,
            'veCobros' => Permisos::puedeRuta('admin.cobros.show'),
            'veRepartos' => $veRepartos,
            'pendienteDeReparto' => $veRepartos && RepartirPrestacion::pendientes()->whereKey($prestacion->id)->exists(),
            'puedeRepartir' => Permisos::puedeRuta('admin.prestaciones.repartir'),
            'puedeTarifar' => $prestacion->pendiente() && Permisos::puedeRuta('admin.prestaciones.tarifar'),
            // Una cobrada no se anula: la reversión del cobro es de la fase 3b.
            'puedeAnular' => ! $prestacion->anulada() && $prestacion->cobroVigente === null && Permisos::puedeRuta('admin.prestaciones.anular'),
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

    /** "Repartir" una pendiente de reparto, con los acuerdos vigentes en su fecha de servicio. Dos veces no duplica. */
    public function repartir(Request $request, Prestacion $prestacion): RedirectResponse
    {
        try {
            $reparto = RepartirPrestacion::repartir($request->user(), $prestacion);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return $reparto->repartido()
            ? back()->with('status', 'Prestación repartida.')
            : back()->with('error', "Sigue pendiente de reparto: {$reparto->motivo_pendiente}");
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

    /**
     * Las consultas sin la base y los informes sin la suya, juntos y los más antiguos primero (hasta SIN_PRESTACION_MAXIMO): cada
     * uno con la consulta, su origen, el procedimiento (solo en los informes) y la ruta de "Generar". Sin contenido clínico.
     */
    private function pendientesDeGenerar($consultas, $informes): Collection
    {
        $relaciones = ['historiaClinica.paciente.persona', 'profesional.persona'];
        $deConsultas = $consultas->with($relaciones)->orderBy('finalizada_en')->limit(self::SIN_PRESTACION_MAXIMO)->get()
            ->map(fn (Consulta $consulta) => ['consulta' => $consulta, 'origen' => Prestacion::ORIGENES['CONSULTA'], 'procedimiento' => null,
                'ruta' => route('admin.prestaciones.generar', $consulta)]);
        $deInformes = $informes->with(['consulta' => fn ($query) => $query->with($relaciones), 'procedimiento'])->limit(self::SIN_PRESTACION_MAXIMO)->get()
            ->map(fn (InformeEstudio $informe) => ['consulta' => $informe->consulta, 'origen' => Prestacion::ORIGENES['INFORME'],
                'procedimiento' => $informe->procedimiento?->nombre, 'ruta' => route('admin.prestaciones.generar-informe', $informe)]);

        return $deConsultas->concat($deInformes)->sortBy(fn (array $fila) => $fila['consulta']->finalizada_en)->take(self::SIN_PRESTACION_MAXIMO)->values();
    }

    /** Un filtro de fecha dd/mm/aaaa válido, en ISO; si no, null. */
    private function fecha(mixed $texto): ?string
    {
        $texto = (string) $texto;

        return preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) && Carbon::canBeCreatedFromFormat($texto, Fecha::FORMATO) ? Fecha::aIso($texto) : null;
    }
}
