<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CupoEntrePacientes;
use App\Models\Profesional;
use App\Support\Agenda;
use App\Support\Fecha;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Cupo "entre pacientes" (Turnos): cuántos pacientes sin horario atiende cada profesional en un día. Se mira con
 * VER sobre DISPONIBILIDAD y se fija con CREAR o EDITAR. Sin fila, el cupo es 0. Ver docs/agenda-urgencias.md.
 */
class CupoEntrePacientesController extends Controller
{
    public const NO_ATIENDE = 'El profesional no atiende ese día.';

    public function index(Request $request): View
    {
        $texto = (string) $request->query('fecha', '');
        $valida = preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) && Carbon::canBeCreatedFromFormat($texto, Fecha::FORMATO);
        $fecha = Carbon::parse($valida ? Fecha::aIso($texto) : Fecha::hoy()->format('Y-m-d'));
        $iso = $fecha->format('Y-m-d');

        $profesionales = Agenda::profesionalesQueAtienden($fecha);
        $cupos = CupoEntrePacientes::whereDate('fecha', $iso)->whereIn('profesional_id', $profesionales->modelKeys())->pluck('cantidad', 'profesional_id');
        $usados = CupoEntrePacientes::usadosPorProfesional($profesionales->modelKeys(), $iso);

        return view('admin.turnos.cupos', [
            'fecha' => $fecha,
            'fechaInvalida' => $texto !== '' && ! $valida,
            'filas' => $profesionales->map(fn (Profesional $profesional) => [
                'profesional' => $profesional,
                'cupo' => (int) ($cupos[$profesional->id] ?? 0),
                'usados' => (int) ($usados[$profesional->id] ?? 0),
            ]),
            'puedeFijar' => $request->user()->tienePermiso('DISPONIBILIDAD', 'CREAR') || $request->user()->tienePermiso('DISPONIBILIDAD', 'EDITAR'),
            'fechaFijable' => self::errorDeFecha($iso) === null,
        ]);
    }

    public function fijar(Request $request, Profesional $profesional): RedirectResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', ...Fecha::regla()],
            'cantidad' => ['required', 'integer', 'min:0', 'max:'.CupoEntrePacientes::MAXIMO],
        ], Fecha::mensajes('fecha'), ['cantidad' => 'cupo']);
        $iso = Fecha::aIso($datos['fecha']);
        $cantidad = (int) $datos['cantidad'];

        if ($error = self::errorDeFecha($iso)) {
            throw ValidationException::withMessages(['fecha' => $error]);
        }
        if (! Agenda::atiende($profesional, Carbon::parse($iso))) {
            throw ValidationException::withMessages(["cantidad.{$profesional->id}" => self::NO_ATIENDE]);
        }

        try {
            $cupo = DB::transaction(fn () => $this->guardar($request, $profesional, $iso, $cantidad));
        } catch (QueryException $e) {
            // Otro envío creó la fila a la vez (único profesional + fecha): se reintenta una vez, ya como edición.
            if (! in_array((string) $e->getCode(), ['23505', '23000'], true)) {
                throw $e;
            }
            $cupo = DB::transaction(fn () => $this->guardar($request, $profesional, $iso, $cantidad));
        }

        return redirect()->route('admin.turnos.cupos', ['fecha' => Fecha::mostrar($iso)])->with('status', sprintf(
            'Cupo entre pacientes de %s el %s: %d.', $profesional->persona->nombre_completo, Fecha::mostrar($iso), $cupo->cantidad));
    }

    /** Con la fila del cupo bloqueada (la misma que bloquea el alta de un entre pacientes): no baja de lo agendado. */
    private function guardar(Request $request, Profesional $profesional, string $iso, int $cantidad): CupoEntrePacientes
    {
        $cupo = CupoEntrePacientes::where('profesional_id', $profesional->id)->whereDate('fecha', $iso)->lockForUpdate()->first();
        $usados = CupoEntrePacientes::usados($profesional->id, $iso);
        if ($cantidad < $usados) {
            throw ValidationException::withMessages(["cantidad.{$profesional->id}" => sprintf(
                'No se puede bajar el cupo a %d: ya hay %d %s agendados ese día.', $cantidad, $usados, $usados === 1 ? 'paciente entre pacientes' : 'pacientes entre pacientes')]);
        }

        if ($cupo) {
            $cupo->update(['cantidad' => $cantidad]);

            return $cupo;
        }

        return CupoEntrePacientes::create(['profesional_id' => $profesional->id, 'fecha' => $iso, 'cantidad' => $cantidad, 'creado_por' => $request->user()->id]);
    }

    /** Solo de hoy en adelante y hasta 60 días. */
    private static function errorDeFecha(string $iso): ?string
    {
        $hoy = Fecha::hoy();

        return match (true) {
            $iso < $hoy->format('Y-m-d') => 'No se fija el cupo de un día que ya pasó.',
            $iso > $hoy->copy()->addDays(CupoEntrePacientes::DIAS_ADELANTE)->format('Y-m-d') => 'El cupo se fija hasta '.CupoEntrePacientes::DIAS_ADELANTE.' días adelante.',
            default => null,
        };
    }
}
