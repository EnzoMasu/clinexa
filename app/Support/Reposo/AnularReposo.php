<?php

namespace App\Support\Reposo;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\ReposoMedico;
use Illuminate\Support\Facades\DB;

/**
 * Anula un certificado de reposo EMITIDO con su motivo (obligatorio). Con la fila bloqueada; lo único que cambia es el
 * estado, la fecha y el motivo de la anulación: el documento y su huella quedan como se emitieron. Queda en Auditoría como
 * ANULAR con el motivo.
 */
final class AnularReposo
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    /** @param  (callable(ReposoMedico): mixed)|null  $autorizar  se llama con el certificado ya bloqueado y releído */
    public static function ejecutar(ReposoMedico $reposo, string $motivo, ?callable $autorizar = null): ReposoMedico
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('El motivo de la anulación debe tener al menos '.self::MOTIVO_MINIMO.' caracteres.');
        }

        return DB::transaction(function () use ($reposo, $motivo, $autorizar) {
            $actual = ReposoMedico::query()->whereKey($reposo->getKey())->lockForUpdate()->firstOrFail();
            if ($autorizar) {
                $autorizar($actual);
            }
            if (! $actual->estaEmitido()) {
                throw new AccionRechazada('Este certificado ya está anulado.');
            }

            $actual->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_en' => now(), 'motivo_anulacion' => mb_substr($motivo, 0, self::MOTIVO_MAXIMO)])->save();
            $actual->unsetRelation('estado');

            return $actual;
        });
    }
}
