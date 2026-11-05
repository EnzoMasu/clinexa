<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\CierreCaja;
use App\Models\Estado;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * La recepción de Gerencia de una caja pendiente (docs/cobros-caja.md): aceptar su último cierre (queda "Recibido", nota opcional) o
 * rechazarlo con la observación obligatoria (queda "Devuelto por diferencias"): el cajero corrige y la reenvía; no recibe cobros y,
 * mientras esté devuelta, su cajero no abre otra caja. Nadie revisa el cierre de su propia caja. Queda en Auditoría: EDITAR del cierre (con la nota) y de la caja. El permiso
 * (EDITAR sobre CIERRES_CAJA) lo controla la ruta.
 */
final class RevisarCierre
{
    public const NOTA_MINIMA = 5;

    public const NOTA_MAXIMA = 300;

    public const PROPIA = 'No puede aceptar ni rechazar el cierre de su propia caja.';

    public const FALTA_MOTIVO = 'Indique la observación con el inconveniente (al menos '.self::NOTA_MINIMA.' caracteres).';

    public static function aprobar(User $revisor, Caja $caja, ?string $nota = null): CierreCaja
    {
        return self::revisar($revisor, $caja, Estado::APROBADO, trim((string) $nota));
    }

    public static function devolver(User $revisor, Caja $caja, string $nota): CierreCaja
    {
        $nota = trim($nota);
        if (mb_strlen($nota) < self::NOTA_MINIMA) {
            throw new AccionRechazada(self::FALTA_MOTIVO);
        }

        return self::revisar($revisor, $caja, Estado::DEVUELTO, $nota);
    }

    private static function revisar(User $revisor, Caja $caja, string $estado, string $nota): CierreCaja
    {
        return DB::transaction(function () use ($revisor, $caja, $estado, $nota) {
            // Primero el cajero (como AbrirCaja): una devolución y una apertura simultáneas del mismo cajero se ordenan.
            DB::table('users')->where('id', Caja::query()->whereKey($caja->getKey())->value('usuario_id'))->lockForUpdate()->first();
            $actual = Caja::query()->whereKey($caja->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->cerrada()) {
                throw new AccionRechazada('Esta caja no está pendiente de recepción.');
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
