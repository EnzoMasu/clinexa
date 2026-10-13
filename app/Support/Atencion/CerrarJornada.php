<?php

namespace App\Support\Atencion;

use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Turno;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Cerrar jornada: los turnos del profesional de hoy y de días anteriores que siguen por atender
 * (PENDIENTE, CONFIRMADO o SALTADO), de cualquier tipo (con horario, entre pacientes o urgencia), pasan a
 * AUSENTE, y sus consultas EN_PREPARACION a ANULADO (con sus datos intactos: no se borra nada), en una
 * transacción. Si tiene consultas EN_CURSO, se rechaza. Si entre los que cierra hay urgencias (URGENCIA o
 * triaje urgente), exige la confirmación "Confirmo que revisé las urgencias". Solo toca sus propios turnos;
 * un segundo envío no encuentra nada que cerrar.
 */
final class CerrarJornada
{
    /** Los turnos que cierra: todavía sin atender. */
    public const POR_CERRAR = [Estado::PENDIENTE, Estado::CONFIRMADO, Estado::SALTADO];

    public const CON_CONSULTAS_EN_CURSO = 'Tiene consultas en curso: finalícelas antes de cerrar la jornada.';

    public const AUN_NO_ES_SU_HORA = 'Aún no es su hora. Si no se va a atender, cancélelos desde Turnos.';

    public const FALTA_CONFIRMAR_URGENCIAS = 'Hay urgencias sin atender: marque "Confirmo que revisé las urgencias" para cerrar la jornada.';

    /**
     * Lo que cerraría (la vista previa).
     *
     * @return Collection<int, Turno>
     */
    public static function turnosSinCerrar(int $profesionalId): Collection
    {
        return Turno::with(['estado', 'paciente.persona', 'consulta:id,turno_id,estado_id,triaje_urgente'])
            ->where('profesional_id', $profesionalId)
            ->whereDate('fecha', '<=', Fecha::hoy()->format('Y-m-d'))
            ->whereIn('estado_id', array_map(fn (string $codigo) => Estado::idDe($codigo), self::POR_CERRAR))
            ->orderBy('fecha')->ordenDelDia()
            ->get();
    }

    /**
     * Pasa a AUSENTE los turnos sin cerrar cuya hora ya llegó (Turno::llegoSuHora); los de hoy que todavía
     * no llegaron a su hora quedan como están. Devuelve [cerrados, pendientes de su hora].
     *
     * @return array{0: int, 1: int}
     */
    public static function ejecutar(User $usuario, bool $confirmoUrgencias = false): array
    {
        return DB::transaction(function () use ($usuario, $confirmoUrgencias) {
            Gate::forUser($usuario)->authorize('cerrarJornada', Consulta::class);
            $profesionalId = $usuario->profesional->id;

            if (Consulta::where('profesional_id', $profesionalId)->where('estado_id', Estado::idDe(Estado::EN_CURSO))->lockForUpdate()->exists()) {
                throw new AccionRechazada(self::CON_CONSULTAS_EN_CURSO);
            }

            $turnos = Turno::with('consulta:id,turno_id,estado_id,triaje_urgente')->whereKey(self::turnosSinCerrar($profesionalId)->modelKeys())->lockForUpdate()->get();
            $urgencias = self::urgenciasQueCierra($turnos);
            if ($urgencias->isNotEmpty() && ! $confirmoUrgencias) {
                throw new AccionRechazada(self::FALTA_CONFIRMAR_URGENCIAS);
            }
            $detalle = $urgencias->isEmpty() ? 'Cierre de jornada' : "Cierre de jornada. Urgencias sin atender revisadas: {$urgencias->count()}";

            return Auditoria::conDetalle($detalle, function () use ($turnos) {
                [$cerrados, $pendientes] = [0, 0];
                foreach ($turnos as $turno) {
                    if (! $turno->llegoSuHora()) {
                        $pendientes++;
                    } elseif ($turno->puedePasarA(Estado::AUSENTE)) {
                        $turno->pasarA(Estado::AUSENTE); // también anula su consulta EN_PREPARACION
                        $cerrados++;
                    }
                }

                return [$cerrados, $pendientes];
            });
        });
    }

    /** Las urgencias (URGENCIA o triaje urgente) entre los turnos que el cierre pasaría a ausente (los que ya llegaron a su hora). */
    public static function urgenciasQueCierra(Collection $turnos): Collection
    {
        return $turnos->filter(fn (Turno $turno) => $turno->llegoSuHora() && PendientesDelProfesional::esUrgencia($turno))->values();
    }
}
