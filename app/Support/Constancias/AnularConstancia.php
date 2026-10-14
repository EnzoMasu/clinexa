<?php

namespace App\Support\Constancias;

use App\Exceptions\AccionRechazada;
use App\Models\Constancia;
use App\Models\Estado;
use Illuminate\Support\Facades\DB;

/**
 * Anula una constancia EMITIDA con su motivo (obligatorio). Con la fila bloqueada; lo único que cambia es el estado, la fecha
 * y el motivo de la anulación: el documento y su huella quedan como se emitieron. Queda en Auditoría como ANULAR con el motivo.
 */
final class AnularConstancia
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    /** @param  (callable(Constancia): mixed)|null  $autorizar  se llama con la constancia ya bloqueada y releída */
    public static function ejecutar(Constancia $constancia, string $motivo, ?callable $autorizar = null): Constancia
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('El motivo de la anulación debe tener al menos '.self::MOTIVO_MINIMO.' caracteres.');
        }

        return DB::transaction(function () use ($constancia, $motivo, $autorizar) {
            $actual = $constancia::query()->whereKey($constancia->getKey())->lockForUpdate()->firstOrFail();
            if ($autorizar) {
                $autorizar($actual);
            }
            if (! $actual->estaEmitida()) {
                throw new AccionRechazada('Esta constancia ya está anulada.');
            }

            $actual->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_en' => now(), 'motivo_anulacion' => mb_substr($motivo, 0, self::MOTIVO_MAXIMO)])->save();
            $actual->unsetRelation('estado');

            return $actual;
        });
    }
}
