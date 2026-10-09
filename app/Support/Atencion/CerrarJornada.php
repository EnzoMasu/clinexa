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
 * (PENDIENTE, CONFIRMADO o SALTADO) pasan a AUSENTE, y sus consultas EN_PREPARACION a ANULADO, en una
 * transacción. Si tiene consultas EN_CURSO, se rechaza. Solo toca sus propios turnos; un segundo envío
 * no encuentra nada que cerrar.
 */
final class CerrarJornada
{
    /** Los turnos que cierra: todavía sin atender. */
    public const POR_CERRAR = [Estado::PENDIENTE, Estado::CONFIRMADO, Estado::SALTADO];

    public const CON_CONSULTAS_EN_CURSO = 'Tiene consultas en curso: finalícelas antes de cerrar la jornada.';

    /**
     * Lo que cerraría (la vista previa).
     *
     * @return Collection<int, Turno>
     */
    public static function turnosSinCerrar(int $profesionalId): Collection
    {
        return Turno::with(['estado', 'paciente.persona'])
            ->where('profesional_id', $profesionalId)
            ->whereDate('fecha', '<=', Fecha::hoy()->format('Y-m-d'))
            ->whereIn('estado_id', array_map(fn (string $codigo) => Estado::idDe($codigo), self::POR_CERRAR))
            ->orderBy('fecha')->orderBy('hora_inicio')
            ->get();
    }

    /** Devuelve cuántos turnos cerró. */
    public static function ejecutar(User $usuario): int
    {
        return DB::transaction(function () use ($usuario) {
            Gate::forUser($usuario)->authorize('cerrarJornada', Consulta::class);
            $profesionalId = $usuario->profesional->id;

            if (Consulta::where('profesional_id', $profesionalId)->where('estado_id', Estado::idDe(Estado::EN_CURSO))->lockForUpdate()->exists()) {
                throw new AccionRechazada(self::CON_CONSULTAS_EN_CURSO);
            }

            $turnos = Turno::whereKey(self::turnosSinCerrar($profesionalId)->modelKeys())->lockForUpdate()->get();

            return Auditoria::conDetalle('Cierre de jornada', function () use ($turnos) {
                $cerrados = 0;
                foreach ($turnos as $turno) {
                    if ($turno->puedePasarA(Estado::AUSENTE)) {
                        $turno->pasarA(Estado::AUSENTE); // también anula su consulta EN_PREPARACION
                        $cerrados++;
                    }
                }

                return $cerrados;
            });
        });
    }
}
