<?php

namespace App\Support\Atencion;

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Turno;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Pasar a ausente" de la pantalla Consulta: el turno de hoy SALTADO (por llamar de nuevo) del profesional
 * pasa a AUSENTE. Turno::pasarA revalida la transición y la hora, y anula la preparación si la había.
 */
final class PasarAusente
{
    public static function ejecutar(User $usuario, Turno $turno): void
    {
        DB::transaction(function () use ($usuario, $turno) {
            $turno = Apoyo::turnoBloqueado($turno);
            Gate::forUser($usuario)->authorize('pasarAusente', [Consulta::class, $turno]);

            Auditoria::conDetalle('Pasar a ausente', fn () => $turno->pasarA(Estado::AUSENTE));
        });
    }
}
