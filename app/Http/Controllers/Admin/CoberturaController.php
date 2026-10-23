<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\CoberturaPaciente;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\LineaVisacion;
use App\Models\Moneda;
use App\Models\Paciente;
use App\Models\PlanConvenio;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Turno;
use App\Models\Visacion;
use App\Support\Convenios\AplicarVisacion;
use App\Support\Convenios\CalcularCobertura;
use App\Support\Convenios\FirmaRecibida;
use App\Support\Convenios\GestionCoberturas;
use App\Support\Convenios\TicketVisacion;
use App\Support\Fecha;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cobertura y visaciones de una paciente (docs/convenios-seguros.md), en una sola página sin entrada en el menú: se llega desde la ficha
 * de la paciente, el turno o la prestación. La cobertura (COBERTURAS), las visaciones de sus turnos y consultas, y sus prestaciones de
 * aseguradora o con aviso, con "Aplicar visación", la firma recibida y el ticket (VISACIONES). Médico y Enfermería no tienen los
 * módulos. Sin contenido clínico.
 */
class CoberturaController extends Controller
{
    /** Las líneas de procedimiento que ofrece el formulario de la visación (las vacías no cuentan). */
    public const LINEAS = 5;

    /** Cuántos días hacia atrás se ofrecen turnos y consultas para la visación. */
    public const DIAS_ATRAS = 60;

    /** Si el usuario puede abrir la página (VER sobre COBERTURAS o VISACIONES): para mostrar los enlaces. */
    public static function puedeVer(): bool
    {
        $usuario = auth()->user();

        return $usuario !== null && ($usuario->tienePermiso('COBERTURAS', 'VER') || $usuario->tienePermiso('VISACIONES', 'VER'));
    }

    public function show(Request $request, Paciente $paciente): View
    {
        $paciente->load('persona.tipoDocumento');
        $usuario = $request->user();
        $veVisaciones = $usuario->tienePermiso('VISACIONES', 'VER');
        $coberturas = CoberturaPaciente::query()->where('paciente_id', $paciente->id)
            ->with(['plan.convenio.responsablePago.persona', 'usuario.persona'])->orderByDesc('vigente_desde')->orderByDesc('id')->get();

        $turno = filter_var($request->query('turno'), FILTER_VALIDATE_INT) !== false
            ? Turno::query()->whereKey((int) $request->query('turno'))->where('paciente_id', $paciente->id)->with('procedimiento')->first() : null;

        return view('admin.coberturas.show', [
            'paciente' => $paciente,
            'turno' => $turno,
            'volver' => self::volverSeguro($request->query('volver')),
            'vigente' => $coberturas->first(fn (CoberturaPaciente $cobertura) => $cobertura->abierta()),
            'coberturas' => $coberturas,
            'planes' => PlanConvenio::query()->where('planes_convenio.estado_id', Estado::idDe(Estado::ACTIVO))
                ->whereHas('convenio', fn ($query) => $query->where('estado_id', Estado::idDe(Estado::VIGENTE)))
                ->with('convenio.responsablePago.persona')->get()
                ->sortBy(fn (PlanConvenio $plan) => $plan->nombreCompleto())->mapWithKeys(fn (PlanConvenio $plan) => [$plan->id => $plan->nombreCompleto()])->all(),
            'veVisaciones' => $veVisaciones,
            'visaciones' => $veVisaciones ? Visacion::query()->whereHas('cobertura', fn ($query) => $query->where('paciente_id', $paciente->id))
                ->with(['responsablePago.persona', 'turno', 'lineas.procedimiento', 'anuladaPor.persona', 'usuario.persona'])
                ->orderByDesc('fecha')->orderByDesc('id')->limit(50)->get() : collect(),
            'destinos' => $veVisaciones ? $this->destinos($paciente) : [],
            'destino' => match (true) {
                $turno !== null => 'turno:'.$turno->id,
                filter_var($request->query('consulta'), FILTER_VALIDATE_INT) !== false => 'consulta:'.(int) $request->query('consulta'),
                default => null,
            },
            'procedimientoDelTurno' => $turno?->procedimiento_id,
            'procedimientos' => Procedimiento::activos()->orderBy('nombre')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->nombre} ({$p->codigo})"])->all(),
            'prestaciones' => $veVisaciones ? $this->prestaciones($paciente) : collect(),
            'veMontos' => $usuario->tienePermiso('PRESTACIONES', 'VER'),
            'moneda' => Moneda::guarani(),
            'puedeCargarCobertura' => Permisos::puedeRuta('admin.coberturas.store'),
            'puedeBajaCobertura' => Permisos::puedeRuta('admin.coberturas.baja'),
            'puedeCargarVisacion' => Permisos::puedeRuta('admin.visaciones.store'),
            'puedeAnularVisacion' => Permisos::puedeRuta('admin.visaciones.anular'),
            'puedeAplicar' => Permisos::puedeRuta('admin.visaciones.aplicar'),
            'puedeTicket' => Permisos::puedeRuta('admin.visaciones.ticket'),
        ]);
    }

    public function guardarCobertura(Request $request, Paciente $paciente): RedirectResponse
    {
        $datos = $request->validate(self::reglasCobertura(), [...Fecha::mensajes('vigente_desde'), ...Fecha::mensajes('vigente_hasta')], self::nombresCobertura());

        try {
            $cobertura = $this->cargarCoberturaDe($request, $paciente, $datos);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.coberturas.show', $paciente)
            ->with('status', $cobertura->reemplaza_a_id ? 'Cobertura cambiada: la anterior quedó en el historial.' : 'Cobertura cargada.');
    }

    public function bajaCobertura(Request $request, CoberturaPaciente $cobertura): RedirectResponse
    {
        $dia = $request->validate(['ultimo_dia' => ['required', ...Fecha::regla()]], Fecha::mensajes('ultimo_dia'), ['ultimo_dia' => 'último día'])['ultimo_dia'];

        try {
            GestionCoberturas::bajaCobertura($request->user(), $cobertura, Carbon::parse(Fecha::aIso($dia)));
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Cobertura dada de baja: quedó en el historial.');
    }

    /**
     * La visación de un turno o consulta. Si la paciente no tiene cobertura vigente (o se pidió cambiarla: `con_cobertura`), los
     * datos de la cobertura llegan en el mismo envío y se guardan junto con la visación en una sola transacción: si falla una, no queda
     * la otra. Después vuelve a la pantalla de origen (`volver`: Agenda general o Turnos) con el aviso.
     */
    public function guardarVisacion(Request $request, Paciente $paciente): RedirectResponse
    {
        $conCobertura = $request->boolean('con_cobertura');
        $reglas = [
            'destino' => ['required', 'string', 'regex:/^(turno|consulta):\d+$/'],
            'numero' => ['required', 'string', 'max:'.GestionCoberturas::NUMERO_MAXIMO],
            'fecha' => ['required', ...Fecha::regla()],
            'lineas' => ['required', 'array'],
            'lineas.*.procedimiento_id' => ['nullable', 'integer'],
            'lineas.*.resultado' => ['nullable', 'string'],
            'lineas.*.copago' => ['nullable', 'string'],
            'lineas.*.valor' => ['nullable', 'string', 'max:20'],
        ];
        $mensajes = ['destino.required' => 'Elija el turno o la consulta de la visación.', 'destino.regex' => 'Elija el turno o la consulta de la visación.', ...Fecha::mensajes('fecha')];
        $nombres = ['numero' => 'número de visación', 'lineas.*.valor' => 'copago'];
        if ($conCobertura) {
            if (! $request->user()->tienePermiso('COBERTURAS', 'CREAR')) {
                return back()->withInput()->with('error', 'No tiene permiso para cargar o cambiar la cobertura.');
            }
            $reglas += self::reglasCobertura();
            $mensajes += [...Fecha::mensajes('vigente_desde'), ...Fecha::mensajes('vigente_hasta')];
            $nombres += self::nombresCobertura();
        }
        $datos = $request->validate($reglas, $mensajes, $nombres);
        [$tipo, $id] = explode(':', $datos['destino']);
        $volver = self::volverSeguro($request->input('volver'));

        try {
            $visacion = DB::transaction(function () use ($request, $paciente, $conCobertura, $datos, $tipo, $id) {
                if ($conCobertura) {
                    $this->cargarCoberturaDe($request, $paciente, $datos);
                }

                return GestionCoberturas::cargarVisacion($request->user(), $paciente, $tipo, (int) $id, $datos['numero'],
                    Carbon::parse(Fecha::aIso($datos['fecha'])), array_values($datos['lineas']));
            });
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect($volver ?? route('admin.coberturas.show', $paciente))
            ->with('status', "Visación N.º {$visacion->numero} cargada".($conCobertura ? ' junto con la cobertura.' : '.'));
    }

    /** Solo una ruta relativa del propio sitio (empieza con una sola "/"): nunca una dirección externa. */
    public static function volverSeguro(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 500 || ! str_starts_with($url, '/') || str_starts_with($url, '//')
            || preg_match('/[\\\\\x00-\x1f]/', $url)) {
            return null;
        }

        return $url;
    }

    private static function reglasCobertura(): array
    {
        return [
            'plan_convenio_id' => ['required', 'integer'],
            'numero_asegurado' => ['required', 'string', 'max:'.GestionCoberturas::NUMERO_MAXIMO],
            'tipo_afiliado' => ['required', 'string'],
            'vigente_desde' => ['required', ...Fecha::regla()],
            'vigente_hasta' => ['nullable', ...Fecha::regla()],
        ];
    }

    private static function nombresCobertura(): array
    {
        return ['plan_convenio_id' => 'plan', 'numero_asegurado' => 'número de asegurado', 'tipo_afiliado' => 'titular o beneficiario',
            'vigente_desde' => 'vigente desde', 'vigente_hasta' => 'vencimiento'];
    }

    private function cargarCoberturaDe(Request $request, Paciente $paciente, array $datos): CoberturaPaciente
    {
        return GestionCoberturas::cargarCobertura($request->user(), $paciente, (int) $datos['plan_convenio_id'], $datos['numero_asegurado'], $datos['tipo_afiliado'],
            Carbon::parse(Fecha::aIso($datos['vigente_desde'])), isset($datos['vigente_hasta']) ? Carbon::parse(Fecha::aIso($datos['vigente_hasta'])) : null);
    }

    public function anularVisacion(Request $request, Visacion $visacion): RedirectResponse
    {
        $motivo = $this->motivo($request, 'motivo_anulacion');

        try {
            GestionCoberturas::anularVisacion($request->user(), $visacion, $motivo);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }
        $aplicadas = Prestacion::where('visacion_id', $visacion->id)->where('estado_id', '!=', Estado::idDe(Estado::ANULADO))->count();

        return back()->with('status', "Visación N.º {$visacion->numero} anulada.")
            ->with('aviso', $aplicadas > 0 ? "Hay {$aplicadas} prestación(es) con esta visación aplicada: quedan como están. Corríjalas a mano (revierta el cobro si lo hubo y aplique otra visación)." : null);
    }

    /** "Aplicar visación" (o volverla particular) a una prestación sin cobro vigente. */
    public function aplicar(Request $request, Prestacion $prestacion): RedirectResponse
    {
        $visacionId = (string) $request->validate(['visacion_id' => ['required']], ['visacion_id.required' => 'Elija la visación (o "Particular").'])['visacion_id'];
        $motivo = $this->motivo($request, 'motivo_aplicacion');
        $visacion = $visacionId === 'PARTICULAR' ? null : Visacion::find((int) $visacionId);
        if ($visacionId !== 'PARTICULAR' && $visacion === null) {
            return back()->with('error', 'La visación elegida no existe.');
        }

        try {
            $prestacion = AplicarVisacion::ejecutar($request->user(), $prestacion, $visacion, $motivo);
        } catch (AccionRechazada $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', $prestacion->deAseguradora()
            ? "Visación aplicada: a cargo de la paciente {$prestacion->montoPacienteTexto()}, de la aseguradora {$prestacion->montoAseguradoraTexto()}."
            : "La prestación quedó particular: {$prestacion->montoTexto()} a cargo de la paciente.");
    }

    public function marcarFirma(Request $request, Prestacion $prestacion): RedirectResponse
    {
        try {
            FirmaRecibida::marcar($request->user(), $prestacion);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Firma marcada como recibida.');
    }

    public function desmarcarFirma(Request $request, Prestacion $prestacion): RedirectResponse
    {
        $motivo = $this->motivo($request, 'motivo_firma');

        try {
            FirmaRecibida::desmarcar($request->user(), $prestacion, $motivo);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Firma desmarcada.');
    }

    /** El ticket de la aseguradora de una consulta (PDF al vuelo, sin montos ni nada clínico). */
    public function ticket(Consulta $consulta): Response
    {
        try {
            return TicketVisacion::ejecutar($consulta);
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function motivo(Request $request, string $campo): string
    {
        return $request->validate(
            [$campo => ['required', 'string', 'min:'.GestionCoberturas::MOTIVO_MINIMO, 'max:'.GestionCoberturas::MOTIVO_MAXIMO]],
            ["{$campo}.required" => 'Indique el motivo.', "{$campo}.min" => 'Indique el motivo (al menos '.GestionCoberturas::MOTIVO_MINIMO.' caracteres).'],
            [$campo => 'motivo'],
        )[$campo];
    }

    /**
     * Los turnos (no cancelados) y las consultas (no anuladas) de la paciente desde hace DIAS_ATRAS días, para elegir a cuál va la
     * visación: ['Turnos' => ['turno:ID' => texto], 'Consultas' => ['consulta:ID' => texto]]. Sin contenido clínico.
     */
    private function destinos(Paciente $paciente): array
    {
        $desde = Fecha::hoy()->subDays(self::DIAS_ATRAS)->format('Y-m-d');
        $turnos = Turno::query()->where('paciente_id', $paciente->id)->whereDate('fecha', '>=', $desde)
            ->where('estado_id', '!=', Estado::idDe(Estado::CANCELADO))->with(['profesional.persona', 'procedimiento'])
            ->orderByDesc('fecha')->orderByDesc('hora_inicio')->limit(40)->get();
        $consultas = Consulta::query()->whereHas('historiaClinica', fn ($query) => $query->where('paciente_id', $paciente->id))
            ->where('estado_id', '!=', Estado::idDe(Estado::ANULADO))->where('fecha_hora', '>=', Carbon::parse($desde))
            ->with('profesional.persona')->orderByDesc('fecha_hora')->limit(40)->get();

        return [
            'Turnos' => $turnos->mapWithKeys(fn (Turno $turno) => ['turno:'.$turno->id => 'Turno del '.Fecha::mostrar($turno->fecha)
                .($turno->tieneHorario() ? ', '.substr($turno->hora_inicio, 0, 5) : '').' · '.$turno->profesional->persona->nombre_completo
                .($turno->procedimiento ? ' · '.$turno->procedimiento->nombre : '')])->all(),
            'Consultas' => $consultas->mapWithKeys(fn (Consulta $consulta) => ['consulta:'.$consulta->id => "Consulta N.º {$consulta->id} del "
                .$consulta->fechaHoraTexto().' · '.$consulta->profesional->persona->nombre_completo])->all(),
        ];
    }

    /**
     * Las prestaciones de la paciente desde el hito de los convenios (las 50 más recientes, no anuladas): las de aseguradora, las que
     * quedaron particulares con un aviso y las que tienen visación en su consulta o turno, con lo necesario para aplicar visación,
     * marcar la firma y sacar el ticket.
     */
    private function prestaciones(Paciente $paciente): Collection
    {
        $inicio = CalcularCobertura::inicio();
        if ($inicio === null) {
            return collect();
        }
        $prestaciones = Prestacion::query()->where('paciente_id', $paciente->id)->where('created_at', '>=', $inicio)
            ->where('estado_id', '!=', Estado::idDe(Estado::ANULADO))
            ->with(['procedimiento', 'moneda', 'visacion', 'responsablePago.persona', 'plan', 'firmaRecibidaPor.persona', 'profesional.persona'])
            ->withExists('cobroVigente')
            ->orderByDesc('fecha_servicio')->orderByDesc('id')->limit(50)->get();
        $visaciones = Visacion::query()->vigentes()->whereHas('cobertura', fn ($query) => $query->where('paciente_id', $paciente->id))
            ->where(fn ($query) => $query->whereIn('consulta_id', $prestaciones->pluck('consulta_id'))->orWhereIn('turno_id', $prestaciones->pluck('turno_id')->filter()))
            ->with('lineas')->get();

        return $prestaciones->map(function (Prestacion $prestacion) use ($visaciones) {
            $prestacion->setAttribute('visaciones_posibles', $visaciones->filter(fn (Visacion $visacion) => ((int) $visacion->consulta_id === (int) $prestacion->consulta_id
                    || ($visacion->turno_id !== null && (int) $visacion->turno_id === (int) $prestacion->turno_id))
                && $visacion->lineas->contains('procedimiento_id', $prestacion->procedimiento_id))->values());

            return $prestacion;
        })->filter(fn (Prestacion $prestacion) => $prestacion->deAseguradora() || $prestacion->aviso_cobertura !== null
            || $prestacion->visacion_id !== null || $prestacion->visaciones_posibles->isNotEmpty())->values();
    }
}
