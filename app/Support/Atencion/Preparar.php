<?php

namespace App\Support\Atencion;

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Turno;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Preparar al paciente de un turno: crea su consulta EN_PREPARACION (profesional del turno, historia del
 * paciente, turno_id), o devuelve la que ya tiene si sigue abierta. El unique de turno_id frena el doble
 * envío: el segundo encuentra la consulta creada por el primero.
 */
final class Preparar
{
    public static function ejecutar(User $usuario, Turno $turno): Consulta
    {
        try {
            return DB::transaction(function () use ($usuario, $turno) {
                $turno = Apoyo::turnoBloqueado($turno);
                if ($existente = Consulta::where('turno_id', $turno->id)->first()) {
                    return Apoyo::consultaDelTurno($existente);
                }
                Gate::forUser($usuario)->authorize('preparar', $turno);

                return Auditoria::conDetalle('Preparar', fn () => Apoyo::crearConsulta($turno->paciente->historiaClinica, $turno, Estado::EN_PREPARACION));
            });
        } catch (QueryException $e) {
            return Apoyo::siYaExiste($e, $turno);
        }
    }
}
