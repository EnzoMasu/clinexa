<?php

namespace App\Support;

use App\Models\Disponibilidad;
use App\Models\Profesional;
use App\Models\Turno;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Horarios libres para dar un turno a un profesional en una fecha:
 *
 * 1. El día de la semana de la fecha.
 * 2. Las disponibilidades ACTIVAS del profesional para ese día, vigentes en esa fecha, en
 *    consultorios activos.
 * 3. Cada franja [hora_desde, hora_hasta) se divide en bloques de duracion_turno_minutos (el
 *    último solo si entra completo).
 * 4. Se descartan los bloques que se pisan con un turno no CANCELADO del profesional, o del
 *    consultorio con otro profesional (la base los rechazaría igual).
 * 5. No hay horarios en el pasado: fechas anteriores a hoy no tienen ninguno y, si es hoy, solo
 *    los que todavía no empezaron (hora de Paraguay). La regla es Fecha::yaLlego, la misma que valida el
 *    alta y los cambios de un turno (TurnoController) y la ausencia desde la hora del turno.
 */
final class Agenda
{
    /**
     * @return Collection<int, array{hora_inicio: string, hora_fin: string, consultorio_id: int, consultorio: string}>
     */
    public static function horariosDisponibles(Profesional $profesional, CarbonInterface $fecha): Collection
    {
        $fecha = Carbon::parse($fecha->format('Y-m-d'));
        $hoy = Fecha::hoy()->format('Y-m-d');

        if (! $profesional->estaActivo() || $fecha->format('Y-m-d') < $hoy) {
            return collect();
        }

        $disponibilidades = Disponibilidad::query()
            ->activos()
            ->where('profesional_id', $profesional->id)
            ->vigentesEn($fecha)
            ->whereHas('consultorio', fn ($query) => $query->activos())
            ->with('consultorio.sucursal')
            ->get();

        if ($disponibilidades->isEmpty()) {
            return collect();
        }

        // Ocupado ese día: turnos del profesional o de alguno de sus consultorios.
        $ocupados = Turno::query()
            ->ocupanHorario()
            ->whereDate('fecha', $fecha->format('Y-m-d'))
            ->where(fn ($query) => $query->where('profesional_id', $profesional->id)
                ->orWhereIn('consultorio_id', $disponibilidades->pluck('consultorio_id')))
            ->get(['profesional_id', 'consultorio_id', 'hora_inicio', 'hora_fin']);

        return $disponibilidades
            ->flatMap(fn (Disponibilidad $disponibilidad) => self::bloques($disponibilidad))
            ->reject(fn (array $bloque) => Fecha::yaLlego($fecha->format('Y-m-d'), $bloque['hora_inicio']))
            ->reject(fn (array $bloque) => $ocupados->contains(fn (Turno $turno) => ($turno->profesional_id === $profesional->id || $turno->consultorio_id === $bloque['consultorio_id'])
                && self::sePisan($bloque['hora_inicio'], $bloque['hora_fin'], Disponibilidad::hora($turno->hora_inicio), Disponibilidad::hora($turno->hora_fin))))
            ->sortBy('hora_inicio')
            ->unique('hora_inicio')
            ->values();
    }

    /** El bloque libre que empieza a esa hora (para guardar el turno), o null si no está libre. */
    public static function horario(Profesional $profesional, CarbonInterface $fecha, string $horaInicio): ?array
    {
        return self::horariosDisponibles($profesional, $fecha)->firstWhere('hora_inicio', $horaInicio);
    }

    /** [desde, hasta) en bloques de la duración del turno; el último solo si entra completo. */
    private static function bloques(Disponibilidad $disponibilidad): array
    {
        $minutos = fn (string $hora) => (int) substr($hora, 0, 2) * 60 + (int) substr($hora, 3, 2);
        $hora = fn (int $minutos) => sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);

        $desde = $minutos(Disponibilidad::hora($disponibilidad->hora_desde));
        $hasta = $minutos(Disponibilidad::hora($disponibilidad->hora_hasta));
        $duracion = max(1, $disponibilidad->duracion_turno_minutos);

        $bloques = [];
        for ($inicio = $desde; $inicio + $duracion <= $hasta; $inicio += $duracion) {
            $bloques[] = [
                'hora_inicio' => $hora($inicio),
                'hora_fin' => $hora($inicio + $duracion),
                'consultorio_id' => $disponibilidad->consultorio_id,
                'consultorio' => $disponibilidad->consultorio->nombre_completo,
            ];
        }

        return $bloques;
    }

    /** Intervalos semiabiertos [a, b) y [c, d): se pisan si a < d y c < b. */
    public static function sePisan(string $inicioA, string $finA, string $inicioB, string $finB): bool
    {
        return $inicioA < $finB && $inicioB < $finA;
    }
}
