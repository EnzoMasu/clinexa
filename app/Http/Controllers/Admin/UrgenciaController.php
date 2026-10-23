<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TipoTurno;
use App\Exceptions\AccionRechazada;
use App\Http\Controllers\Controller;
use App\Models\Estado;
use App\Models\Paciente;
use App\Models\Profesional;
use App\Models\Turno;
use App\Support\Agenda;
use App\Support\Auditoria;
use App\Support\BuscadorPersonas;
use App\Support\Convenios\EstadoVisacionTurnos;
use App\Support\Fecha;
use App\Support\Turnos\RegistrarUrgencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Urgencias de recepción (pantalla Turnos): registrar una (CREAR sobre TURNOS), ver las de hoy (VER) y cancelar
 * una registrada por error mientras está PENDIENTE (EDITAR). Nada clínico: ni observación del triaje, ni signos,
 * ni las urgencias que marcó el triaje; solo los turnos URGENCIA. Sin texto libre. Ver docs/agenda-urgencias.md.
 */
class UrgenciaController extends Controller
{
    public function index(): View
    {
        $urgencias = Turno::delTipo(TipoTurno::URGENCIA)
            ->with(['paciente.persona', 'profesional.persona', 'estado'])
            ->whereDate('fecha', Fecha::hoy()->format('Y-m-d'))
            ->orderBy('llegada_en')->orderBy('id')
            ->get();

        $visacionesTurnos = EstadoVisacionTurnos::para($urgencias);

        return view('admin.urgencias.index', compact('urgencias', 'visacionesTurnos'));
    }

    public function create(): View
    {
        $profesionales = Profesional::query()->activos()->with('persona')->get();
        $hoy = Agenda::profesionalesQueAtienden(Fecha::hoy())->modelKeys();
        $orden = fn (Profesional $profesional) => $profesional->persona->apellidos.' '.$profesional->persona->nombres;
        [$atiendenHoy, $otros] = $profesionales->partition(fn (Profesional $profesional) => in_array($profesional->id, $hoy, true));

        $pacienteId = old('paciente_id');
        $paciente = $pacienteId ? Paciente::with('persona.tipoDocumento')->find($pacienteId) : null;

        return view('admin.urgencias.form', [
            'atiendenHoy' => $atiendenHoy->sortBy($orden)->values(),
            'otros' => $otros->sortBy($orden)->values(),
            'pacienteInicial' => $paciente ? ['id' => $paciente->id, 'texto' => BuscadorPersonas::textoRol($paciente, fn ($p) => "Ficha {$p->nro_ficha}")] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $activo = Estado::idDe(Estado::ACTIVO);
        $datos = $request->validate([
            'paciente_id' => ['required', 'integer', Rule::exists('pacientes', 'id')->where('estado_id', $activo)],
            'profesional_id' => ['required', 'integer', Rule::exists('profesionales', 'id')->where('estado_id', $activo)],
            'agendar_igual' => ['nullable', 'boolean'],
        ], [
            'paciente_id.exists' => 'El paciente no está registrado o está inactivo.',
            'profesional_id.exists' => 'El profesional no existe o está inactivo.',
        ], ['paciente_id' => 'paciente', 'profesional_id' => 'profesional']);

        try {
            $turno = RegistrarUrgencia::ejecutar((int) $datos['paciente_id'], (int) $datos['profesional_id'], filter_var($datos['agendar_igual'] ?? false, FILTER_VALIDATE_BOOLEAN));
        } catch (AccionRechazada $e) {
            return back()->withInput()->withErrors(['paciente_id' => $e->getMessage()]);
        }

        return redirect()->route('admin.turnos.urgencias')->with('status', sprintf('Urgencia registrada: %s, con %s.',
            $turno->paciente->persona->nombre_completo, $turno->profesional->persona->nombre_completo));
    }

    /** Cancelar una urgencia registrada por error: solo PENDIENTE. Con el turno bloqueado. */
    public function cancelar(Turno $turno): RedirectResponse
    {
        $resultado = DB::transaction(function () use ($turno) {
            $turno = Turno::with('estado')->whereKey($turno->id)->lockForUpdate()->firstOrFail();
            if ($turno->tipo !== TipoTurno::URGENCIA) {
                abort(404);
            }
            if (! $turno->tieneEstado(Estado::PENDIENTE)) {
                return 'Solo se cancela una urgencia pendiente (esta está '.mb_strtolower($turno->estado->nombre).').';
            }
            Auditoria::conDetalle('Urgencia cancelada', fn () => $turno->pasarA(Estado::CANCELADO));

            return null;
        });

        return $resultado
            ? back()->with('error', $resultado)
            : redirect()->route('admin.turnos.urgencias')->with('status', 'Urgencia cancelada.');
    }
}
