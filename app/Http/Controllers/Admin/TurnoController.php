<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\CupoEntrePacientes;
use App\Models\Estado;
use App\Models\OrigenTurno;
use App\Models\Paciente;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Turno;
use App\Support\Agenda;
use App\Support\Auditoria;
use App\Support\BuscadorPersonas;
use App\Support\Fecha;
use App\Support\Atencion\RegistrarSalida;
use App\Support\Turnos\AgendarEntrePacientes;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/**
 * Turnos: listado, alta (eligiendo un horario libre de la agenda del profesional) y cambios de
 * estado. No hay edición ni borrado: para cambiar un turno se cancela y se da otro.
 */
class TurnoController extends Controller
{
    /** SQLSTATE de PostgreSQL cuando un EXCLUDE rechaza la fila (turno superpuesto). */
    private const SUPERPOSICION = '23P01';

    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        // Una fecha completa (dd/mm/aaaa) filtra por fecha; cualquier otro texto, por paciente o profesional.
        $fecha = preg_match('#^\d{2}/\d{2}/\d{4}$#', $busqueda) && Carbon::canBeCreatedFromFormat($busqueda, Fecha::FORMATO)
            ? Fecha::aIso($busqueda) : null;

        $turnos = Turno::query()
            ->select('turnos.*')
            ->join('pacientes', 'pacientes.id', '=', 'turnos.paciente_id')
            ->join('personas as persona_paciente', 'persona_paciente.id', '=', 'pacientes.persona_id')
            ->join('profesionales', 'profesionales.id', '=', 'turnos.profesional_id')
            ->join('personas as persona_profesional', 'persona_profesional.id', '=', 'profesionales.persona_id')
            ->with(['paciente.persona.tipoDocumento', 'profesional.persona', 'consultorio.sucursal', 'procedimiento', 'origenTurno', 'consulta:id,turno_id,estado_id,preparada_en'])
            ->when($fecha, fn ($query) => $query->whereDate('turnos.fecha', $fecha))
            ->when($busqueda !== '' && ! $fecha, fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('persona_paciente.apellidos', "%{$busqueda}%")
                ->orWhereLike('persona_paciente.nombres', "%{$busqueda}%")
                ->orWhereLike('persona_paciente.nro_documento', "{$busqueda}%")
                ->orWhereLike('pacientes.nro_ficha', "%{$busqueda}%")
                ->orWhereLike('persona_profesional.apellidos', "%{$busqueda}%")
                ->orWhereLike('persona_profesional.nombres', "%{$busqueda}%")))
            ->orderByDesc('turnos.fecha')
            ->ordenDelDia()
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.turnos', compact('turnos', 'busqueda'));
    }

    public function create(): View
    {
        // Prellenado desde la Agenda general (?profesional=&fecha=&hora=): solo valores bien formados; el alta se valida igual.
        $profesionalPedido = ctype_digit((string) request('profesional')) ? Profesional::activos()->find((int) request('profesional'))?->id : null;
        $fechaPedida = preg_match('#^\d{2}/\d{2}/\d{4}$#', (string) request('fecha')) && Carbon::canBeCreatedFromFormat(request('fecha'), Fecha::FORMATO) ? request('fecha') : null;
        $horaPedida = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) request('hora')) ? request('hora') : '';

        return view('admin.turnos.form', [
            'pacienteInicial' => $this->inicial(Paciente::class, old('paciente_id'), fn ($p) => "Ficha {$p->nro_ficha}"),
            'profesionalInicial' => $this->inicial(Profesional::class, old('profesional_id', $profesionalPedido), fn ($p) => "Mat. {$p->matricula}"),
            // Fecha del turno: hoy, editable.
            'fecha' => old('fecha', $fechaPedida ?? Fecha::mostrar(Fecha::hoy())),
            'hora' => old('hora_inicio', $horaPedida),
            'modalidad' => old('modalidad') === self::ENTRE_PACIENTES || request('modalidad') === self::ENTRE_PACIENTES ? self::ENTRE_PACIENTES : self::CON_HORARIO,
            'procedimientos' => Procedimiento::activos()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'origenes' => OrigenTurno::activos()->orderBy('nombre')->pluck('nombre', 'id')->all(),
        ]);
    }

    /** Modalidad del alta: "con horario" (la de siempre) o "entre pacientes" (sin horario, dentro del cupo). */
    public const CON_HORARIO = 'con_horario';

    public const ENTRE_PACIENTES = 'entre_pacientes';

    public const FECHA_PASADA = 'No se agendan turnos en una fecha pasada.';

    /** El horario elegido ya empezó (o la fecha ya pasó): Fecha::yaLlego, la regla de los horarios libres. */
    public const HORARIO_PASADO = 'Ese horario ya pasó. Elija uno posterior.';

    public function store(Request $request): RedirectResponse
    {
        if ($request->input('modalidad') === self::ENTRE_PACIENTES) {
            return $this->storeEntrePacientes($request);
        }

        ['segundoDelDia' => $segundoDelDia] = $datos = $this->validar($request);
        unset($datos['segundoDelDia']);

        try {
            // En su propia transacción: si la base lo rechaza, se deshace solo esto (en PostgreSQL un
            // error deja inutilizable la transacción en curso). Un segundo turno del día con el mismo
            // profesional (confirmado con "Agendar igual") lo dice en el detalle de su CREAR.
            $crear = fn () => DB::transaction(fn () => Turno::create($datos));
            $turno = $segundoDelDia ? Auditoria::conDetalle('Segundo turno del día confirmado', $crear) : $crear();
        } catch (QueryException $e) {
            if ($e->getCode() === self::SUPERPOSICION) {
                // La base rechazó la superposición: otro turno se guardó entre la validación y el alta. Si es
                // de la paciente (EXCLUDE turnos_sin_superposicion_paciente), el mismo aviso que la validación.
                if (str_contains($e->getMessage(), 'turnos_sin_superposicion_paciente')) {
                    $otro = Turno::superpuestoDeLaPaciente((int) $datos['paciente_id'], $datos['fecha'], $datos['hora_inicio'], $datos['hora_fin']);

                    return back()->withInput()->withErrors(['hora_inicio' => $otro?->avisoSuperposicionPaciente() ?? 'La paciente ya tiene otro turno en ese horario.']);
                }

                return back()->withInput()->withErrors(['hora_inicio' => 'Ese horario se acaba de ocupar. Elija otro.']);
            }
            throw $e;
        }

        return redirect()->route('admin.turnos.index')->with('status', sprintf(
            'Turno dado para el %s a las %s (%s).', Fecha::mostrar($turno->fecha), $datos['hora_inicio'], $turno->consultorio->nombre_completo));
    }

    /**
     * Alta "entre pacientes": sin horario ni consultorio, dentro del cupo del profesional ese día
     * (AgendarEntrePacientes, con la fila del cupo bloqueada). No se agenda en una fecha pasada.
     */
    private function storeEntrePacientes(Request $request): RedirectResponse
    {
        $activo = Estado::idDe(Estado::ACTIVO);
        $validador = validator($request->all(), [
            'paciente_id' => ['required', 'integer', Rule::exists('pacientes', 'id')->where('estado_id', $activo)],
            'profesional_id' => ['required', 'integer', Rule::exists('profesionales', 'id')->where('estado_id', $activo)],
            'fecha' => ['required', ...Fecha::regla()],
            'procedimiento_id' => ['nullable', 'integer', Rule::exists('procedimientos', 'id')->where('estado_id', $activo)],
            'origen_turno_id' => ['nullable', 'integer', Rule::exists('origenes_turno', 'id')->where('estado_id', $activo)],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'agendar_igual' => ['nullable', 'boolean'],
        ], Fecha::mensajes('fecha'), [
            'paciente_id' => 'paciente', 'profesional_id' => 'profesional',
            'procedimiento_id' => 'procedimiento', 'origen_turno_id' => 'origen del turno',
        ]);
        $validador->after(function (Validator $validador) {
            if ($validador->errors()->isEmpty() && Fecha::aIso($validador->getData()['fecha']) < Fecha::hoy()->format('Y-m-d')) {
                $validador->errors()->add('fecha', self::FECHA_PASADA);
            }
        });
        $datos = $validador->validate();
        $datos['fecha'] = Fecha::aIso($datos['fecha']);

        try {
            $turno = AgendarEntrePacientes::ejecutar($datos, filter_var($datos['agendar_igual'] ?? false, FILTER_VALIDATE_BOOLEAN));
        } catch (AccionRechazada $e) {
            return back()->withInput()->withErrors(['cupo' => $e->getMessage()]);
        }

        return redirect()->route('admin.turnos.index')->with('status', sprintf('Turno entre pacientes dado para el %s (cupo: %d de %d usados).',
            Fecha::mostrar($turno->fecha), CupoEntrePacientes::usados($turno->profesional_id, $datos['fecha']),
            (int) CupoEntrePacientes::where('profesional_id', $turno->profesional_id)->whereDate('fecha', $datos['fecha'])->value('cantidad')));
    }

    /** Cupo entre pacientes de un profesional en una fecha (JSON {cupo, usados}), para el alta. Vacío si faltan datos. */
    public function cupoDelDia(Request $request): JsonResponse
    {
        $validador = validator($request->query(), ['profesional_id' => ['required', 'integer'], 'fecha' => ['required', ...Fecha::regla()]]);
        if ($validador->fails()) {
            return response()->json([]);
        }
        ['profesional_id' => $profesionalId, 'fecha' => $fecha] = $validador->validated();
        $iso = Fecha::aIso($fecha);

        return response()->json([
            'cupo' => (int) CupoEntrePacientes::where('profesional_id', $profesionalId)->whereDate('fecha', $iso)->value('cantidad'),
            'usados' => CupoEntrePacientes::usados((int) $profesionalId, $iso),
        ]);
    }

    /**
     * Horarios libres de un profesional en una fecha (JSON), para el alta de turno. Sin profesional
     * o sin una fecha dd/mm/aaaa válida responde una lista vacía (como los buscadores con poco texto).
     */
    public function horariosDisponibles(Request $request): JsonResponse
    {
        $validador = validator($request->query(), [
            'profesional_id' => ['required', 'integer'],
            'fecha' => ['required', ...Fecha::regla()],
        ]);
        if ($validador->fails()) {
            return response()->json([]);
        }
        $datos = $validador->validated();

        $profesional = Profesional::find($datos['profesional_id']);
        if (! $profesional) {
            return response()->json([]);
        }

        return response()->json(Agenda::horariosDisponibles($profesional, Carbon::parse(Fecha::aIso($datos['fecha'])))
            ->map(fn (array $bloque) => [
                'hora_inicio' => $bloque['hora_inicio'],
                'hora_fin' => $bloque['hora_fin'],
                'consultorio' => $bloque['consultorio'],
            ]));
    }

    /** Buscador de pacientes ACTIVOS (personas que ya tienen el rol), por nombre, documento o ficha. */
    public function pacientes(Request $request): JsonResponse
    {
        return BuscadorPersonas::responderRol(Paciente::query(), (string) $request->query('q'), ['nro_ficha'],
            fn (Paciente $paciente) => "Ficha {$paciente->nro_ficha}");
    }

    /** Buscador de profesionales ACTIVOS, por nombre, documento o matrícula. */
    public function profesionales(Request $request): JsonResponse
    {
        return BuscadorPersonas::responderRol(Profesional::query(), (string) $request->query('q'), ['matricula'],
            fn (Profesional $profesional) => "Mat. {$profesional->matricula}");
    }

    /** Botones manuales: confirmar, marcar ausente o cancelar, según las transiciones válidas del estado actual. */
    public function cambiarEstado(Request $request, Turno $turno): RedirectResponse
    {
        $accion = $request->validate(['accion' => ['required', Rule::in(array_keys(Turno::ACCIONES))]])['accion'];

        if (! $turno->puede($accion)) {
            if (in_array($accion, ['cancelar', 'ausente'], true) && $turno->triajeTerminado()) {
                return back()->with('error', Turno::SOLO_RETIRO);
            }

            return back()->with('error', sprintf('No se puede %s un turno %s.', mb_strtolower(Turno::ACCIONES[$accion][1]), mb_strtolower($turno->estado->nombre)));
        }

        // pasarA revalida (transición y, para la ausencia, la hora del turno) y, si corresponde, anula la
        // consulta EN_PREPARACION del turno. Retiro y rechazo pasan por RegistrarSalida (deja el registro).
        try {
            if (in_array($accion, ['retiro', 'rechazo'], true)) {
                RegistrarSalida::ejecutar($request->user(), $turno, mb_strtoupper($accion));
            } else {
                $turno->pasarA(Turno::ACCIONES[$accion][0]);
            }
        } catch (AccionRechazada $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', sprintf('Turno del %s %s: %s.',
            Fecha::mostrar($turno->fecha), $turno->tieneHorario() ? 'a las '.substr($turno->hora_inicio, 0, 5) : '('.mb_strtolower($turno->tipo->etiqueta()).')', mb_strtolower($turno->fresh()->estado->nombre)));
    }

    /**
     * Valida el alta y completa lo que no se pide: el consultorio y la hora de fin salen del horario
     * libre elegido (de la disponibilidad que lo generó).
     */
    private function validar(Request $request): array
    {
        $activo = Estado::idDe(Estado::ACTIVO);

        $validador = validator($request->all(), [
            'paciente_id' => ['required', 'integer', Rule::exists('pacientes', 'id')->where('estado_id', $activo)],
            'profesional_id' => ['required', 'integer', Rule::exists('profesionales', 'id')->where('estado_id', $activo)],
            'fecha' => ['required', ...Fecha::regla()],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'procedimiento_id' => ['nullable', 'integer', Rule::exists('procedimientos', 'id')->where('estado_id', $activo)],
            'origen_turno_id' => ['nullable', 'integer', Rule::exists('origenes_turno', 'id')->where('estado_id', $activo)],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'agendar_igual' => ['nullable', 'boolean'],
        ], [
            ...Fecha::mensajes('fecha'),
            'hora_inicio.required' => 'Elija uno de los horarios libres.',
        ], [
            'paciente_id' => 'paciente', 'profesional_id' => 'profesional', 'hora_inicio' => 'horario',
            'procedimiento_id' => 'procedimiento', 'origen_turno_id' => 'origen del turno',
        ]);

        $horario = null;
        $segundoDelDia = false;
        $validador->after(function (Validator $validador) use (&$horario, &$segundoDelDia) {
            if ($validador->errors()->isNotEmpty()) {
                return;
            }
            $datos = $validador->getData();

            // No se agenda en el pasado: la misma regla que el cálculo de horarios libres (Fecha::yaLlego). Un día
            // anterior, o hoy un horario que ya empezó (en punto incluido), se rechaza con un aviso propio.
            $fecha = Fecha::aIso($datos['fecha']);
            if (Fecha::yaLlego($fecha, $datos['hora_inicio'])) {
                $validador->errors()->add($fecha < Fecha::hoy()->format('Y-m-d') ? 'fecha' : 'hora_inicio', self::HORARIO_PASADO);

                return;
            }

            $horario = Agenda::horario(Profesional::findOrFail($datos['profesional_id']), Carbon::parse(Fecha::aIso($datos['fecha'])), $datos['hora_inicio']);

            if (! $horario) {
                // Si lo que ocupa esa hora es un turno de la misma paciente (con este u otro profesional), el
                // aviso es el de la paciente; si no, el horario simplemente no está libre.
                $deLaPaciente = Turno::superpuestoDeLaPaciente((int) $datos['paciente_id'], $fecha, $datos['hora_inicio'],
                    Carbon::parse($datos['hora_inicio'])->addMinute()->format('H:i'));
                $validador->errors()->add('hora_inicio', $deLaPaciente?->avisoSuperposicionPaciente()
                    ?? 'Ese horario no está disponible para el profesional en esa fecha. Elija uno de los horarios libres.');

                return;
            }

            // La paciente no puede tener dos turnos activos que se superpongan, con ningún profesional.
            $superpuesto = Turno::superpuestoDeLaPaciente((int) $datos['paciente_id'], $fecha, $horario['hora_inicio'], $horario['hora_fin']);
            if ($superpuesto) {
                $validador->errors()->add('hora_inicio', $superpuesto->avisoSuperposicionPaciente());

                return;
            }

            // Otro turno activo ese día con el mismo profesional: se agenda solo con "Agendar igual".
            $otro = Turno::otroDelDiaConElProfesional((int) $datos['paciente_id'], (int) $datos['profesional_id'], $fecha);
            $segundoDelDia = $otro !== null;
            if ($otro && ! filter_var($datos['agendar_igual'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $validador->errors()->add('agendar_igual', $otro->avisoSegundoDelDia());
            }
        });

        $datos = $validador->validate();
        unset($datos['agendar_igual']);

        return [
            'segundoDelDia' => $segundoDelDia,
            ...$datos,            'fecha' => Fecha::aIso($datos['fecha']),
            'hora_fin' => $horario['hora_fin'],
            'consultorio_id' => $horario['consultorio_id'],
        ];
    }

    /** Lo elegido antes en un buscador (tras un error de validación), para mostrarlo de nuevo. */
    private function inicial(string $modelo, mixed $id, \Closure $detalle): ?array
    {
        $registro = $id ? $modelo::with('persona.tipoDocumento')->find($id) : null;

        return $registro ? ['id' => $registro->id, 'texto' => BuscadorPersonas::textoRol($registro, $detalle)] : null;
    }
}
