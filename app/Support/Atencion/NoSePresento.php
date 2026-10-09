<?php

namespace App\Support\Atencion;

use App\Models\Estado;
use App\Models\Turno;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** "No se presentó": el turno de hoy, PENDIENTE o CONFIRMADO, pasa a SALTADO (se lo vuelve a llamar). */
final class NoSePresento
{
    public static function ejecutar(User $usuario, Turno $turno): void
    {
        DB::transaction(function () use ($usuario, $turno) {
            $turno = Apoyo::turnoBloqueado($turno);
            Gate::forUser($usuario)->authorize('noSePresento', $turno);

            Auditoria::conDetalle('No se presentó', fn () => $turno->pasarA(Estado::SALTADO));
        });
    }
}
