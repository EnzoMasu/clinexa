<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\CierreCaja;
use App\Models\Estado;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * La revisión de Gerencia de una caja cerrada (docs/cobros-caja.md): aprobar su último cierre o devolverlo con una nota. La
 * devuelta queda DEVUELTO: el cajero vuelve a declarar lo contado y la cierra de nuevo; no recibe cobros ni impide abrir la del
 * día. Nadie revisa el cierre de su propia caja. Queda en Auditoría: EDITAR del cierre (con la nota) y de la caja. El permiso
 * (EDITAR sobre CIERRES_CAJA) lo controla la ruta.
 */
final class RevisarCierre
{
    public const NOTA_MINIMA = 5;

    public const NOTA_MAXIMA = 300;

    public const PROPIA = 'No puede aprobar ni devolver el cierre de su propia caja.';

    public static function aprobar(User $revisor, Caja $caja, ?string $nota = null): CierreCaja
    {
        return self::revisar($revisor, $caja, Estado::APROBADO, trim((string) $nota));
    }

    public static function devolver(User $revisor, Caja $caja, string $nota): CierreCaja
    {
        $nota = trim($nota);
        if (mb_strlen($nota) < self::NOTA_MINIMA) {
            throw new AccionRechazada('Indique qué hay que corregir (al menos '.self::NOTA_MINIMA.' caracteres).');
        }

        return self::revisar($revisor, $caja, Estado::DEVUELTO, $nota);
    }

    private static function revisar(User $revisor, Caja $caja, string $estado, string $nota): CierreCaja
    {
        return DB::transaction(function () use ($revisor, $caja, $estado, $nota) {
            $actual = Caja::query()->whereKey($caja->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->cerrada()) {
                throw new AccionRechazada('Esta caja no está pendiente de aprobación.');
            }
            if ($actual->usuario_id === $revisor->id) {
                throw new AccionRechazada(self::PROPIA);
            }
            $cierre = $actual->cierres()->where('estado_id', Estado::idDe(Estado::PENDIENTE))->latest('id')->lockForUpdate()->firstOrFail();

            $cierre->forceFill([
                'estado_id' => Estado::idDe($estado),
                'revisado_por' => $revisor->id,
                'revisado_en' => now(),
                'nota_revision' => $nota === '' ? null : mb_substr($nota, 0, self::NOTA_MAXIMA),
            ])->save();
            $actual->forceFill(['estado_id' => Estado::idDe($estado)])->save();
            $cierre->unsetRelation('estado');

            return $cierre;
        });
    }
}
