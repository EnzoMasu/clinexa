<?php

namespace App\Support\Atencion;

use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Turno;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Atender un turno: con turno y consulta bloqueados, la consulta EN_PREPARACION pasa a EN_CURSO (o se crea
 * EN_CURSO si no se preparó), iniciada_en = ahora, y el turno pasa a EN_CONSULTA. Un segundo envío (doble
 * clic) encuentra la consulta ya en curso y la devuelve sin repetir nada.
 */
final class Atender
{
    public static function ejecutar(User $usuario, Turno $turno): Consulta
    {
        try {
            return DB::transaction(function () use ($usuario, $turno) {
                $turno = Apoyo::turnoBloqueado($turno);
                $consulta = Consulta::where('turno_id', $turno->id)->lockForUpdate()->first();

                if ($consulta?->enCurso() && $turno->tieneEstado(Estado::EN_CONSULTA)) {
                    Gate::forUser($usuario)->authorize('escribirClinico', $consulta);

                    return $consulta; // doble clic: ya se está atendiendo
                }
                Gate::forUser($usuario)->authorize('atenderTurno', $turno);
                if ($consulta && ! $consulta->enPreparacion()) {
                    throw new AccionRechazada('Este turno ya tiene una consulta ('.mb_strtolower($consulta->estado->nombre).'): no se puede volver a atender.');
                }

                return Auditoria::conDetalle('Atender', function () use ($turno, $consulta) {
                    if ($consulta) {
                        $consulta->forceFill(['estado_id' => Estado::idDe(Estado::EN_CURSO), 'iniciada_en' => now()])->save();
                        $consulta->unsetRelation('estado');
                    } else {
                        $consulta = Apoyo::crearConsulta($turno->paciente->historiaClinica, $turno, Estado::EN_CURSO);
                    }
                    $turno->pasarA(Estado::EN_CONSULTA);

                    return $consulta;
                });
            });
        } catch (QueryException $e) {
            return Apoyo::siYaExiste($e, $turno);
        }
    }
}
