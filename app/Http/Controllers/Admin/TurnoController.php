<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Estado;
use App\Models\OrigenTurno;
use App\Models\Paciente;
use App\Models\Procedimiento;
use App\Models\Profesional;
use App\Models\Turno;
use App\Support\Agenda;
use App\Support\BuscadorPersonas;
use App\Support\Fecha;
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
            ->with(['paciente.persona.tipoDocumento', 'profesional.persona', 'consultorio.sucursal', 'procedimiento', 'origenTurno'])
            ->when($fecha, fn ($query) => $query->whereDate('turnos.fecha', $fecha))
            ->when($busqueda !== '' && ! $fecha, fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('persona_paciente.apellidos', "%{$busqueda}%")
                ->orWhereLike('persona_paciente.nombres', "%{$busqueda}%")
                ->orWhereLike('persona_paciente.nro_documento', "{$busqueda}%")
                ->orWhereLike('pacientes.nro_ficha', "%{$busqueda}%")
                ->orWhereLike('persona_profesional.apellidos', "%{$busqueda}%")
                ->orWhereLike('persona_profesional.nombres', "%{$busqueda}%")))
            ->orderByDesc('turnos.fecha')
            ->orderBy('turnos.hora_inicio')
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.turnos', compact('turnos', 'busqueda'));
    }

    public function create(): View
    {
        return view('admin.turnos.form', [
            'pacienteInicial' => $this->inicial(Paciente::class, old('paciente_id'), fn ($p) => "Ficha {$p->nro_ficha}"),
            'profesionalInicial' => $this->inicial(Profesional::class, old('profesional_id'), fn ($p) => "Mat. {$p->matricula}"),
            // Fecha del turno: hoy, editable.
            'fecha' => old('fecha', Fecha::mostrar(Fecha::hoy())),
            'procedimientos' => Procedimiento::activos()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'origenes' => OrigenTurno::activos()->orderBy('nombre')->pluck('nombre', 'id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        try {
            // En su propia transacción: si la base lo rechaza, se deshace solo esto (en PostgreSQL un
            // error deja inutilizable la transacción en curso).
            $turno = DB::transaction(fn () => Turno::create($datos));
        } catch (QueryException $e) {
            // La base rechazó la superposición: otro turno ocupó ese horario entre que se eligió y se guardó.
            if ($e->getCode() === self::SUPERPOSICION) {
                return back()->withInput()->withErrors(['hora_inicio' => 'Ese horario se acaba de ocupar. Elija otro.']);
            }
            throw $e;
        }

        return redirect()->route('admin.turnos.index')->with('status', sprintf(
            'Turno dado para el %s a las %s (%s).', Fecha::mostrar($turno->fecha), $datos['hora_inicio'], $turno->consultorio->nombre_completo));
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
            return back()->with('error', sprintf('No se puede %s un turno %s.', mb_strtolower(Turno::ACCIONES[$accion][1]), mb_strtolower($turno->estado->nombre)));
        }

        // pasarA revalida y, si corresponde, anula la consulta EN_PREPARACION del turno.
        $turno->pasarA(Turno::ACCIONES[$accion][0]);

        return back()->with('status', sprintf('Turno del %s a las %s: %s.',
            Fecha::mostrar($turno->fecha), substr($turno->hora_inicio, 0, 5), mb_strtolower($turno->fresh()->estado->nombre)));
    }

    /**
     * Valida el alta y completa lo que no se pide: el consultorio y la hora de fin salen del horario
     * libre elegido (de la disponibilidad que lo generó).
     */
    private function validar(Request $request): array
    {
        $activo = Estado::idDe(Estado::ACTIVO);
        $hoy = Fecha::hoy()->format(Fecha::FORMATO);

        $validador = validator($request->all(), [
            'paciente_id' => ['required', 'integer', Rule::exists('pacientes', 'id')->where('estado_id', $activo)],
            'profesional_id' => ['required', 'integer', Rule::exists('profesionales', 'id')->where('estado_id', $activo)],
            'fecha' => ['required', ...Fecha::regla(), "after_or_equal:{$hoy}"],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'procedimiento_id' => ['nullable', 'integer', Rule::exists('procedimientos', 'id')->where('estado_id', $activo)],
            'origen_turno_id' => ['nullable', 'integer', Rule::exists('origenes_turno', 'id')->where('estado_id', $activo)],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            ...Fecha::mensajes('fecha'),
            'fecha.after_or_equal' => 'No se pueden dar turnos en fechas pasadas.',
            'hora_inicio.required' => 'Elija uno de los horarios libres.',
        ], [
            'paciente_id' => 'paciente', 'profesional_id' => 'profesional', 'hora_inicio' => 'horario',
            'procedimiento_id' => 'procedimiento', 'origen_turno_id' => 'origen del turno',
        ]);

        $horario = null;
        $validador->after(function (Validator $validador) use (&$horario) {
            if ($validador->errors()->isNotEmpty()) {
                return;
            }
            $datos = $validador->getData();
            $horario = Agenda::horario(Profesional::findOrFail($datos['profesional_id']), Carbon::parse(Fecha::aIso($datos['fecha'])), $datos['hora_inicio']);

            if (! $horario) {
                $validador->errors()->add('hora_inicio', 'Ese horario no está disponible para el profesional en esa fecha. Elija uno de los horarios libres.');
            }
        });

        $datos = $validador->validate();

        return [
            ...$datos,
            'fecha' => Fecha::aIso($datos['fecha']),
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
