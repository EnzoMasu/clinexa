<?php

namespace App\Support\Compras;

use App\Exceptions\AccionRechazada;
use App\Models\Compra;
use App\Models\Estado;
use App\Models\User;
use App\Support\Fecha;
use Illuminate\Support\Facades\DB;

/**
 * Confirmar y anular una compra (docs/compras-gastos.md). Con la fila bloqueada y releída, así que dos pedidos a la vez no pisan nada.
 * Nada se borra, y confirmar y anular no tocan la caja (fase posterior).
 *
 * - Confirmar: solo un borrador, y nunca por quien lo cargó (tampoco el Administrador). Queda quién y cuándo; Auditoría lo muestra
 *   como EDITAR con el detalle "Confirmación de la compra".
 * - Anular: un borrador (se descarta) o una confirmada, con motivo obligatorio (5 a 300 caracteres), sin plazo ni segunda
 *   aprobación. Queda quién, cuándo y el motivo; Auditoría la muestra como ANULAR con el valor anterior y el nuevo. Al anularse, su
 *   número de comprobante queda libre para el control de duplicados.
 *
 * Los permisos los controla la ruta (CONFIRMACION_COMPRAS EDITAR; COMPRAS VER) y la CompraPolicy (confirmar lo ajeno; anular con
 * DESACTIVAR, o descartar el borrador propio).
 */
final class FlujoCompra
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    public const SOLO_BORRADOR = 'Solo se confirma una compra en borrador.';

    public const YA_ANULADA = 'Esta compra ya está anulada.';

    /** @param  (callable(Compra): mixed)|null  $autorizar  se llama con la compra ya bloqueada y releída */
    public static function confirmar(User $usuario, Compra $compra, ?callable $autorizar = null): Compra
    {
        return DB::transaction(function () use ($usuario, $compra, $autorizar) {
            $actual = self::bloquear($compra);
            if ($autorizar) {
                $autorizar($actual);
            }
            if (! $actual->esBorrador()) {
                throw new AccionRechazada(self::SOLO_BORRADOR);
            }

            $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::CONFIRMADO),
                'confirmada_por' => $usuario->id,
                'confirmada_en' => Fecha::ahora(),
            ])->save();
            $actual->unsetRelation('estado');

            return $actual;
        });
    }

    /** @param  (callable(Compra): mixed)|null  $autorizar  se llama con la compra ya bloqueada y releída */
    public static function anular(User $usuario, Compra $compra, ?string $motivo, ?callable $autorizar = null): Compra
    {
        $motivo = trim((string) $motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('El motivo de la anulación debe tener al menos '.self::MOTIVO_MINIMO.' caracteres.');
        }
        if (mb_strlen($motivo) > self::MOTIVO_MAXIMO) {
            throw new AccionRechazada('El motivo de la anulación admite hasta '.self::MOTIVO_MAXIMO.' caracteres.');
        }

        return DB::transaction(function () use ($usuario, $compra, $motivo, $autorizar) {
            $actual = self::bloquear($compra);
            if ($actual->tieneEstado(Estado::ANULADO)) {
                throw new AccionRechazada(self::YA_ANULADA);
            }
            if ($autorizar) {
                $autorizar($actual);
            }

            $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::ANULADO),
                'anulada_por' => $usuario->id,
                'anulada_en' => Fecha::ahora(),
                'motivo_anulacion' => $motivo,
            ])->save();
            $actual->unsetRelation('estado');

            return $actual;
        });
    }

    private static function bloquear(Compra $compra): Compra
    {
        return Compra::query()->whereKey($compra->getKey())->lockForUpdate()->firstOrFail();
    }
}
