<?php

namespace App\Support\Prestaciones;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\Prestacion;
use App\Models\User;
use App\Support\Repartos\RepartirPrestacion;
use Illuminate\Support\Facades\DB;

/**
 * Anula una prestación (pendiente o tarifada) con su motivo. Nada se borra: cambian el estado, la fecha, el motivo y quién.
 * Su reparto, si tiene, también queda anulado. Una prestación con un cobro vigente no se anula (docs/cobros-caja.md). Queda en
 * Auditoría como ANULAR con el motivo. El permiso (DESACTIVAR sobre PRESTACIONES: Administrador y Gerencia) lo controla la ruta.
 */
final class AnularPrestacion
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    public const COBRADA = 'Esta prestación está cobrada: no se puede anular mientras su cobro esté vigente.';

    public static function ejecutar(User $usuario, Prestacion $prestacion, string $motivo): Prestacion
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('El motivo de la anulación debe tener al menos '.self::MOTIVO_MINIMO.' caracteres.');
        }

        return DB::transaction(function () use ($usuario, $prestacion, $motivo) {
            $actual = Prestacion::query()->whereKey($prestacion->getKey())->lockForUpdate()->firstOrFail();
            if ($actual->anulada()) {
                throw new AccionRechazada('Esta prestación ya está anulada.');
            }
            // Una cobrada no se anula: primero habría que revertir el cobro, que llega en la fase 3b (docs/cobros-caja.md).
            if ($actual->cobroVigente()->exists()) {
                throw new AccionRechazada(self::COBRADA);
            }

            $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::ANULADO),
                'anulada_en' => now(),
                'motivo_anulacion' => mb_substr($motivo, 0, self::MOTIVO_MAXIMO),
                'anulada_por' => $usuario->id,
            ])->save();
            $actual->unsetRelation('estado');
            // Su reparto también se anula, con el mismo motivo (docs/reparto-economico.md).
            RepartirPrestacion::anular($usuario, $actual, $actual->motivo_anulacion);

            return $actual;
        });
    }
}
