<?php

namespace App\Support\Convenios;

use App\Exceptions\AccionRechazada;
use App\Models\Prestacion;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Liquidaciones\BloqueoLiquidacion;
use Illuminate\Support\Facades\DB;

/**
 * "Firma recibida" (docs/convenios-seguros.md): la paciente firmó el ticket de una prestación de aseguradora. Se marca con fecha y
 * usuario; se desmarca con motivo. Ni una ni otra en una prestación que
 * está en una liquidación no anulada (BloqueoLiquidacion, docs/liquidaciones-aseguradoras.md). En Auditoría, EDITAR con el detalle. El permiso (EDITAR sobre
 * VISACIONES: Recepción y Facturación y convenios) lo controla la ruta.
 */
final class FirmaRecibida
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    public static function marcar(User $usuario, Prestacion $prestacion): Prestacion
    {
        return DB::transaction(function () use ($usuario, $prestacion) {
            $actual = self::bloqueada($prestacion);
            if ($actual->firma_recibida_en !== null) {
                throw new AccionRechazada('La firma de esta prestación ya está marcada como recibida.');
            }
            Auditoria::conDetalle('Firma recibida', fn () => $actual->forceFill(['firma_recibida_en' => now(), 'firma_recibida_por' => $usuario->id])->save());

            return $actual;
        });
    }

    public static function desmarcar(User $usuario, Prestacion $prestacion, string $motivo): Prestacion
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('Indique el motivo (al menos '.self::MOTIVO_MINIMO.' caracteres).');
        }

        return DB::transaction(function () use ($prestacion, $motivo) {
            $actual = self::bloqueada($prestacion);
            if ($actual->firma_recibida_en === null) {
                throw new AccionRechazada('La firma de esta prestación no está marcada.');
            }
            Auditoria::conDetalle('Firma desmarcada. Motivo: '.mb_substr($motivo, 0, self::MOTIVO_MAXIMO),
                fn () => $actual->forceFill(['firma_recibida_en' => null, 'firma_recibida_por' => null])->save());

            return $actual;
        });
    }

    /** La prestación bloqueada, si es de aseguradora, no está anulada ni en una liquidación. */
    private static function bloqueada(Prestacion $prestacion): Prestacion
    {
        $actual = Prestacion::query()->whereKey($prestacion->getKey())->lockForUpdate()->firstOrFail();
        if (! $actual->deAseguradora() || $actual->anulada()) {
            throw new AccionRechazada('La firma solo se marca en prestaciones de aseguradora no anuladas.');
        }
        if (($impedimento = BloqueoLiquidacion::impedimento($actual)) !== null) {
            throw new AccionRechazada($impedimento);
        }

        return $actual;
    }
}
