<?php

namespace App\Support\Atencion;

use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Turno;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deshacer atención (consulta EN_CURSO del profesional):
 * - con turno, si no hay contenido clínico (motivo, hallazgos, diagnósticos o indicaciones activos, recetas
 *   sin anular): la consulta vuelve a EN_PREPARACION (sin iniciada_en; preparada_en se conserva) y el turno a
 *   PENDIENTE. Los datos de la preparación no lo impiden. La consulta no se anula.
 * - sin turno (urgencia), solo si está completamente vacía: pasa a ANULADO.
 */
final class DeshacerAtencion
{
    public const CON_CONTENIDO = 'No se puede deshacer: la consulta ya tiene motivo, hallazgos, diagnósticos, indicaciones o recetas.';

    /** Devuelve 'preparacion' (volvió a preparación) o 'anulada'. */
    public static function ejecutar(User $usuario, Consulta $consulta): string
    {
        return DB::transaction(function () use ($usuario, $consulta) {
            $consulta = Apoyo::consultaBloqueada($consulta);
            Gate::forUser($usuario)->authorize('deshacer', $consulta);

            if (Apoyo::tieneContenidoClinico($consulta)) {
                throw new AccionRechazada(self::CON_CONTENIDO);
            }

            return Auditoria::conDetalle('Deshacer atención', function () use ($consulta) {
                if ($consulta->turno_id === null) {
                    if (Apoyo::tienePreparacion($consulta)) {
                        throw new AccionRechazada('No se puede deshacer: la consulta sin turno ya tiene datos cargados.');
                    }
                    $consulta->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO)])->save();

                    return 'anulada';
                }

                $turno = Turno::whereKey($consulta->turno_id)->lockForUpdate()->firstOrFail();
                if (! $turno->tieneEstado(Estado::EN_CONSULTA)) {
                    throw new AccionRechazada('No se puede deshacer: el turno cambió desde otra ventana.');
                }
                $consulta->forceFill(['estado_id' => Estado::idDe(Estado::EN_PREPARACION), 'iniciada_en' => null])->save();
                $turno->pasarA(Estado::PENDIENTE);

                return 'preparacion';
            });
        });
    }
}
