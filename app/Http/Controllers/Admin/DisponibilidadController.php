<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultorio;
use App\Models\Disponibilidad;
use App\Models\Estado;
use App\Models\Profesional;
use App\Support\BuscadorPersonas;
use App\Support\Fecha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class DisponibilidadController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        $disponibilidades = Disponibilidad::query()
            ->select('disponibilidades.*')
            ->join('profesionales', 'profesionales.id', '=', 'disponibilidades.profesional_id')
            ->join('personas', 'personas.id', '=', 'profesionales.persona_id')
            ->join('consultorios', 'consultorios.id', '=', 'disponibilidades.consultorio_id')
            ->with(['profesional.persona', 'consultorio.sucursal'])
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('personas.apellidos', "%{$busqueda}%")
                ->orWhereLike('personas.nombres', "%{$busqueda}%")
                ->orWhereLike('consultorios.nombre', "%{$busqueda}%")))
            ->orderBy('personas.apellidos')
            // Lunes a domingo (no alfabético).
            ->orderByRaw("CASE disponibilidades.dia_semana WHEN 'LUN' THEN 1 WHEN 'MAR' THEN 2 WHEN 'MIE' THEN 3 WHEN 'JUE' THEN 4 WHEN 'VIE' THEN 5 WHEN 'SAB' THEN 6 ELSE 7 END")
            ->orderBy('disponibilidades.hora_desde')
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.disponibilidades', compact('disponibilidades', 'busqueda'));
    }

    public function create(): View
    {
        // Vigencia desde: hoy, editable.
        return $this->formulario(new Disponibilidad(['vigencia_desde' => Fecha::hoy(), 'duracion_turno_minutos' => 30]));
    }

    public function store(Request $request): RedirectResponse
    {
        Disponibilidad::create($this->validar($request));

        return redirect()->route('admin.disponibilidades.index')->with('status', 'Disponibilidad creada.');
    }

    public function edit(Disponibilidad $disponibilidad): View
    {
        return $this->formulario($disponibilidad->load('profesional.persona.tipoDocumento'));
    }

    public function update(Request $request, Disponibilidad $disponibilidad): RedirectResponse
    {
        $disponibilidad->update($this->validar($request, $disponibilidad));

        return redirect()->route('admin.disponibilidades.index')->with('status', 'Disponibilidad actualizada.');
    }

    public function desactivar(Disponibilidad $disponibilidad): RedirectResponse
    {
        $disponibilidad->desactivar();

        return redirect()->route('admin.disponibilidades.index')->with('status', 'Disponibilidad desactivada.');
    }

    /** Buscador de profesionales ACTIVOS (JSON) para elegir de quién es la disponibilidad. */
    public function profesionales(Request $request): JsonResponse
    {
        return BuscadorPersonas::responderRol(Profesional::query(), (string) $request->query('q'), ['matricula'],
            fn (Profesional $profesional) => "Mat. {$profesional->matricula}");
    }

    private function formulario(Disponibilidad $disponibilidad): View
    {
        $profesionalId = old('profesional_id', $disponibilidad->profesional_id);
        $profesional = $profesionalId ? Profesional::with('persona.tipoDocumento')->find($profesionalId) : null;

        return view('admin.disponibilidades.form', [
            'disponibilidad' => $disponibilidad,
            'profesionalInicial' => $profesional ? ['id' => $profesional->id, 'texto' => BuscadorPersonas::textoRol($profesional, fn ($p) => "Mat. {$p->matricula}")] : null,
            // Consultorios activos, más el que ya tenga aunque esté inactivo.
            'consultorios' => Consultorio::query()->with('sucursal')
                ->where(fn ($query) => $query->activos()->when($disponibilidad->consultorio_id, fn ($query) => $query->orWhere('id', $disponibilidad->consultorio_id)))
                ->orderBy('nombre')->get()->mapWithKeys(fn (Consultorio $consultorio) => [$consultorio->id => $consultorio->nombre_completo])->all(),
        ]);
    }

    private function validar(Request $request, ?Disponibilidad $disponibilidad = null): array
    {
        $activo = Estado::idDe(Estado::ACTIVO);
        $reglas = [
            'profesional_id' => ['required', 'integer', Rule::exists('profesionales', 'id')->where(fn ($query) => $query
                ->where('estado_id', $activo)->when($disponibilidad, fn ($query) => $query->orWhere('id', $disponibilidad->profesional_id)))],
            'consultorio_id' => ['required', 'integer', Rule::exists('consultorios', 'id')->where(fn ($query) => $query
                ->where('estado_id', $activo)->when($disponibilidad, fn ($query) => $query->orWhere('id', $disponibilidad->consultorio_id)))],
            'dia_semana' => ['required', Rule::in(array_keys(Disponibilidad::DIAS))],
            'hora_desde' => ['required', 'date_format:H:i'],
            'hora_hasta' => ['required', 'date_format:H:i', 'after:hora_desde'],
            'duracion_turno_minutos' => ['required', 'integer', 'min:5', 'max:480'],
            'vigencia_desde' => ['required', ...Fecha::regla()],
            'vigencia_hasta' => ['nullable', ...Fecha::regla(), 'after:vigencia_desde'],
        ];
        if ($disponibilidad) {
            $reglas['estado_id'] = Disponibilidad::reglaEstado();
        }

        $validador = validator($request->all(), $reglas, [
            ...Fecha::mensajes('vigencia_desde'),
            ...Fecha::mensajes('vigencia_hasta'),
            'hora_desde.date_format' => 'El campo hora desde debe tener el formato HH:MM (por ejemplo, 08:00).',
            'hora_hasta.date_format' => 'El campo hora hasta debe tener el formato HH:MM (por ejemplo, 12:00).',
            'hora_hasta.after' => 'La hora hasta debe ser posterior a la hora desde.',
            'vigencia_hasta.after' => 'La vigencia hasta debe ser posterior a la vigencia desde.',
        ], [
            'profesional_id' => 'profesional', 'consultorio_id' => 'consultorio', 'dia_semana' => 'día de la semana',
            'hora_desde' => 'hora desde', 'hora_hasta' => 'hora hasta', 'duracion_turno_minutos' => 'duración del turno',
            'vigencia_desde' => 'vigencia desde', 'vigencia_hasta' => 'vigencia hasta',
        ]);

        $validador->after(function (Validator $validador) use ($disponibilidad) {
            if ($validador->errors()->isNotEmpty()) {
                return;
            }
            $datos = $validador->getData();

            [$desde, $hasta] = [$this->minutos($datos['hora_desde']), $this->minutos($datos['hora_hasta'])];
            if ($hasta - $desde < (int) $datos['duracion_turno_minutos']) {
                $validador->errors()->add('duracion_turno_minutos', 'La franja horaria no alcanza para un turno de esa duración.');

                return;
            }

            // Solo se controla la superposición si queda ACTIVA.
            $quedaActiva = ! $disponibilidad || (int) ($datos['estado_id'] ?? 0) === Estado::idDe(Estado::ACTIVO);
            if ($quedaActiva && ($conflicto = $this->superpuesta($datos, $disponibilidad))) {
                $validador->errors()->add('hora_desde', $conflicto);
            }
        });

        $datos = $validador->validate();

        return [
            ...$datos,
            'vigencia_desde' => Fecha::aIso($datos['vigencia_desde']),
            'vigencia_hasta' => Fecha::aIso($datos['vigencia_hasta'] ?? null),
        ];
    }

    /**
     * Otra disponibilidad ACTIVA del mismo profesional o del mismo consultorio, el mismo día de la
     * semana, con horario y vigencia que se pisan: mensaje de error, o null si no hay.
     */
    private function superpuesta(array $datos, ?Disponibilidad $propia): ?string
    {
        $desde = Fecha::aIso($datos['vigencia_desde']);
        $hasta = Fecha::aIso($datos['vigencia_hasta'] ?? null);

        $otra = Disponibilidad::query()
            ->activos()
            ->with(['profesional.persona', 'consultorio.sucursal'])
            ->when($propia, fn ($query) => $query->whereKeyNot($propia->id))
            ->where('dia_semana', $datos['dia_semana'])
            ->where(fn ($query) => $query->where('profesional_id', $datos['profesional_id'])->orWhere('consultorio_id', $datos['consultorio_id']))
            ->where('hora_desde', '<', $datos['hora_hasta'])
            ->where('hora_hasta', '>', $datos['hora_desde'])
            ->when($hasta, fn ($query) => $query->whereDate('vigencia_desde', '<=', $hasta))
            ->where(fn ($query) => $query->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $desde))
            ->first();

        if (! $otra) {
            return null;
        }

        $quien = (int) $otra->profesional_id === (int) $datos['profesional_id']
            ? 'el mismo profesional'
            : "el consultorio {$otra->consultorio->nombre_completo} (lo usa {$otra->profesional->persona->nombre_completo})";

        return sprintf('Se superpone con otra disponibilidad activa de %s: %s de %s a %s, vigente desde el %s%s.',
            $quien, Disponibilidad::DIAS[$otra->dia_semana], Disponibilidad::hora($otra->hora_desde), Disponibilidad::hora($otra->hora_hasta),
            Fecha::mostrar($otra->vigencia_desde), $otra->vigencia_hasta ? ' hasta el '.Fecha::mostrar($otra->vigencia_hasta) : '');
    }

    private function minutos(string $hora): int
    {
        return (int) substr($hora, 0, 2) * 60 + (int) substr($hora, 3, 2);
    }
}
