<?php

namespace App\Support\Atencion;

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\HistoriaClinica;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Atender sin turno (urgencia): crea la consulta EN_CURSO, sin turno, iniciada ahora. */
final class AtenderSinTurno
{
    public static function ejecutar(User $usuario, HistoriaClinica $historia): Consulta
    {
        return DB::transaction(function () use ($usuario, $historia) {
            $historia->load('paciente');
            Gate::forUser($usuario)->authorize('atenderSinTurno', $historia);

            return Auditoria::conDetalle('Atender sin turno',
                fn () => Apoyo::crearConsulta($historia, null, Estado::EN_CURSO, $usuario->profesional->id));
        });
    }
}
