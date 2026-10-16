<?php

namespace App\Support\OrdenEstudio;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\OrdenEstudio;
use Illuminate\Support\Facades\DB;

/**
 * Anula una orden de estudio EMITIDA con su motivo (obligatorio). Con la fila bloqueada; lo único que cambia es el estado, la
 * fecha y el motivo de la anulación: el documento y su huella quedan como se emitieron. Queda en Auditoría como ANULAR con
 * el motivo.
 */
final class AnularOrden
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    /** @param  (callable(OrdenEstudio): mixed)|null  $autorizar  se llama con la orden ya bloqueada y releída */
    public static function ejecutar(OrdenEstudio $orden, string $motivo, ?callable $autorizar = null): OrdenEstudio
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('El motivo de la anulación debe tener al menos '.self::MOTIVO_MINIMO.' caracteres.');
        }

        return DB::transaction(function () use ($orden, $motivo, $autorizar) {
            $actual = OrdenEstudio::query()->whereKey($orden->getKey())->lockForUpdate()->firstOrFail();
            if ($autorizar) {
                $autorizar($actual);
            }
            if (! $actual->estaEmitida()) {
                throw new AccionRechazada('Esta orden ya está anulada.');
            }

            $actual->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_en' => now(), 'motivo_anulacion' => mb_substr($motivo, 0, self::MOTIVO_MAXIMO)])->save();
            $actual->unsetRelation('estado');

            return $actual;
        });
    }
}
