<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TipoTurno;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\CupoEntrePacientes;
use App\Models\Disponibilidad;
use App\Models\Especialidad;
use App\Models\Estado;
use App\Models\Profesional;
use App\Models\Turno;
use App\Support\Agenda;
use App\Support\Convenios\EstadoVisacionTurnos;
use App\Support\Fecha;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Agenda general (grupo Agenda, VER sobre TURNOS): los turnos de un día de todos los profesionales, una columna por
 * profesional, para que recepción busque disponibilidad. Solo datos de agenda: del triaje se muestra únicamente si
 * hay o no (Sin triaje / En triaje / Con triaje); nunca la urgencia marcada por el triaje, la observación, los signos
 * ni diagnósticos (de la consulta solo se leen id, turno, estado y preparada_en). Una cantidad fija de consultas SQL,
 * con paginado de profesionales. Ver docs/agenda-urgencias.md.
 */
class AgendaGeneralController extends Controller
{
    /** Profesionales por página. */
    public const POR_PAGINA = 10;

    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $texto = (string) $request->query('fecha', '');
        $valida = preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) && Carbon::canBeCreatedFromFormat($texto, Fecha::FORMATO);
        $fecha = Carbon::parse($valida ? Fecha::aIso($texto) : Fecha::hoy()->format('Y-m-d'));
        $iso = $fecha->format('Y-m-d');

        $profesionalId = $this->entero($request->query('profesional'));
        $especialidadId = $this->entero($request->query('especialidad'));
        $estadoCodigo = in_array($request->query('estado'), array_keys(self::ESTADOS), true) ? $request->query('estado') : null;
        $tipo = TipoTurno::tryFrom((string) $request->query('tipo'));

        // Turnos que se muestran: con los filtros de estado, tipo y búsqueda. Sin elegir estado no se muestran los cancelados.
        $coincide = fn () => Turno::query()
            ->join('pacientes', 'pacientes.id', '=', 'turnos.paciente_id')
            ->join('personas as persona_paciente', 'persona_paciente.id', '=', 'pacientes.persona_id')
            ->whereDate('turnos.fecha', $iso)
            ->when($estadoCodigo, fn ($query) => $query->where('turnos.estado_id', Estado::idDe($estadoCodigo)),
                fn ($query) => $query->ocupanHorario())
            ->when($tipo, fn ($query) => $query->delTipo($tipo))
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('persona_paciente.apellidos', "%{$busqueda}%")
                ->orWhereLike('persona_paciente.nombres', "%{$busqueda}%")
                ->orWhereLike('persona_paciente.nro_documento', "{$busqueda}%")
                ->orWhereLike('pacientes.nro_ficha', "%{$busqueda}%")));
        $filtrado = $busqueda !== '' || $estadoCodigo || $tipo;

        // Columnas: profesionales activos (con los filtros de profesional y especialidad) que atienden ese día o tienen
        // turnos; si se filtra por estado, tipo o paciente, solo los que tienen turnos que coinciden.
        $profesionales = Profesional::query()->activos()
            ->join('personas', 'personas.id', '=', 'profesionales.persona_id')
            ->when($profesionalId, fn ($query) => $query->where('profesionales.id', $profesionalId))
            ->when($especialidadId, fn ($query) => $query->whereIn('profesionales.id', fn ($sub) => $sub->select('profesional_id')
                ->from('profesional_especialidad')->where('especialidad_id', $especialidadId)->where('activa', true)))
            ->where(fn ($query) => $filtrado
                ? $query->whereIn('profesionales.id', $coincide()->select('turnos.profesional_id'))
                : $query->whereIn('profesionales.id', Agenda::disponibilidadesDelDia($fecha)->select('profesional_id'))
                    ->orWhereIn('profesionales.id', Turno::query()->ocupanHorario()->whereDate('fecha', $iso)->select('profesional_id')))
            ->orderBy('personas.apellidos')->orderBy('personas.nombres')
            ->select('profesionales.*')
            ->with(['persona', 'especialidadesActivas'])
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
        $ids = $profesionales->getCollection()->modelKeys();

        $disponibilidades = $ids === [] ? collect() : Agenda::disponibilidadesDelDia($fecha)->whereIn('profesional_id', $ids)->with('consultorio.sucursal')->get();
        $consultorios = $disponibilidades->pluck('consultorio_id')->unique()->values()->all();
        $ocupados = $ids === [] ? collect() : Turno::query()->ocupanHorario()->whereNotNull('hora_inicio')->whereDate('fecha', $iso)
            ->where(fn ($query) => $query->whereIn('profesional_id', $ids)->orWhereIn('consultorio_id', $consultorios))
            ->get(['profesional_id', 'consultorio_id', 'hora_inicio', 'hora_fin']);

        $turnos = $ids === [] ? collect() : $coincide()->select('turnos.*')
            ->whereIn('turnos.profesional_id', $ids)
            ->with(['paciente.persona', 'estado', 'consulta' => fn ($query) => $query->visibles()->select(['id', 'turno_id', 'estado_id', 'preparada_en'])])
            ->ordenDelDia()
            ->get();

        $cupos = $ids === [] ? collect() : CupoEntrePacientes::whereDate('fecha', $iso)->whereIn('profesional_id', $ids)->pluck('cantidad', 'profesional_id');
        $usados = $ids === [] ? collect() : CupoEntrePacientes::usadosPorProfesional($ids, $iso);

        $columnas = $profesionales->getCollection()->map(fn (Profesional $profesional) => [
            'profesional' => $profesional,
            'turnos' => $turnos->where('profesional_id', $profesional->id),
            'cupo' => (int) ($cupos[$profesional->id] ?? 0),
            'usados' => (int) ($usados[$profesional->id] ?? 0),
        ]);

        $datos = [
            'fecha' => $fecha,
            'fechaInvalida' => $texto !== '' && ! $valida,
            'busqueda' => $busqueda,
            'profesionales' => $profesionales,
            'columnas' => $columnas,
            'filas' => $this->filas($columnas, $disponibilidades, $ocupados, $iso),
            'filtros' => ['fecha' => Fecha::mostrar($fecha), 'profesional' => $profesionalId, 'especialidad' => $especialidadId, 'estado' => $estadoCodigo, 'tipo' => $tipo?->value, 'q' => $busqueda],
            'especialidadFiltrada' => (bool) $especialidadId,
            'puedeAgendar' => $request->user()->tienePermiso('TURNOS', 'CREAR'),
            'puedeCambiarEstado' => $request->user()->tienePermiso('TURNOS', 'EDITAR'),
            'visacionesTurnos' => EstadoVisacionTurnos::para($turnos),
        ];

        if (! $request->ajax()) {
            $datos += [
                'opcionesProfesional' => Profesional::activos()->with('persona')->get()->sortBy(fn ($p) => $p->persona->nombre_completo)
                    ->mapWithKeys(fn ($p) => [$p->id => $p->persona->nombre_completo])->all(),
                'opcionesEspecialidad' => Especialidad::activos()->orderBy('nombre')->pluck('nombre', 'id')->all(),
                'opcionesEstado' => self::ESTADOS,
                'opcionesTipo' => collect(TipoTurno::cases())->mapWithKeys(fn (TipoTurno $caso) => [$caso->value => $caso->etiqueta()])->all(),
            ];
        }

        return $this->listado($request, 'admin.agenda-general', $datos);
    }

    /** Estados de turno que se pueden filtrar (código => texto). Sin elegir ninguno, se ocultan los cancelados. */
    public const ESTADOS = [
        Estado::PENDIENTE => 'Pendiente',
        Estado::CONFIRMADO => 'Confirmado',
        Estado::SALTADO => 'Saltado',
        Estado::EN_CONSULTA => 'En consulta',
        Estado::ATENDIDO => 'Atendido',
        Estado::AUSENTE => 'Ausente',
        Estado::RETIRADO => 'Retirado',
        Estado::CANCELADO => 'Cancelado',
    ];

    /**
     * Las filas de la grilla: cada hora de comienzo (de las disponibilidades de los profesionales mostrados o de sus
     * turnos con horario) con una celda por profesional: ['turnos' => [...]] ocupada, ['continua' => turno] tomada por
     * un turno que empezó antes (uno largo, p. ej. de un procedimiento de 120 minutos), ['libre' => bloque] libre,
     * ['ocupado' => true] ocupada por un turno que los filtros ocultan, o null (no atiende a esa hora).
     *
     * @return Collection<string, array<int, array|null>> hora => profesional_id => celda
     */
    private function filas(Collection $columnas, Collection $disponibilidades, Collection $ocupados, string $iso): Collection
    {
        $bloques = $disponibilidades->groupBy('profesional_id')->map(fn (Collection $delProfesional) => $delProfesional
            ->flatMap(fn (Disponibilidad $disponibilidad) => Agenda::bloques($disponibilidad))->sortBy('hora_inicio')->unique('hora_inicio')->keyBy('hora_inicio'));

        $horas = $columnas->flatMap(fn (array $columna) => $columna['turnos']->filter->tieneHorario()->map(fn (Turno $turno) => Disponibilidad::hora($turno->hora_inicio)))
            ->merge($bloques->flatMap(fn (Collection $delProfesional) => $delProfesional->keys()))
            ->unique()->sort()->values();

        // Sin las filas donde ningún profesional tiene nada (horas que ya pasaron).
        return $horas->mapWithKeys(fn (string $hora) => [$hora => $columnas->mapWithKeys(function (array $columna) use ($hora, $bloques, $ocupados, $iso) {
            $id = $columna['profesional']->id;
            $turnos = $columna['turnos']->filter(fn (Turno $turno) => $turno->tieneHorario() && Disponibilidad::hora($turno->hora_inicio) === $hora)->values();
            $bloque = $bloques[$id][$hora] ?? null;

            if ($turnos->isNotEmpty()) {
                return [$id => ['turnos' => $turnos]];
            }
            $continua = $columna['turnos']->first(fn (Turno $turno) => $turno->tieneHorario()
                && Disponibilidad::hora($turno->hora_inicio) < $hora && $hora < Disponibilidad::hora($turno->hora_fin));
            if ($continua) {
                return [$id => ['continua' => $continua]];
            }
            if (! $bloque || Fecha::yaLlego($iso, $hora)) {
                return [$id => null];
            }
            $ocupa = $ocupados->contains(fn (Turno $turno) => ($turno->profesional_id === $id || $turno->consultorio_id === $bloque['consultorio_id'])
                && Agenda::sePisan($bloque['hora_inicio'], $bloque['hora_fin'], Disponibilidad::hora($turno->hora_inicio), Disponibilidad::hora($turno->hora_fin)));

            return [$id => $ocupa ? ['ocupado' => true] : ['libre' => $bloque]];
        })])->filter(fn (Collection $celdas) => $celdas->filter()->isNotEmpty());
    }

    private function entero(mixed $valor): ?int
    {
        return ctype_digit((string) $valor) && (int) $valor > 0 ? (int) $valor : null;
    }
}
