<?php

namespace App\Support\Cobros;

use App\Exceptions\AccionRechazada;
use App\Models\Caja;
use App\Models\Cobro;
use App\Models\LineaCobro;
use App\Models\MedioPago;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * Corrige el comprobante y el medio de pago de una línea de cobro en un arqueo devuelto (docs/cobros-caja.md). Nunca el monto, y no
 * revierte el cobro: la línea y su movimiento de caja pasan al medio nuevo, y lo esperado por medio se recalcula solo.
 *
 * Reglas: la caja del cobro está DEVUELTA (por diferencias) y quien corrige es su cajero; el cobro sigue vigente; el medio nuevo, si
 * cambia, está activo; si el medio (nuevo o el mismo) exige comprobante no puede quedar vacío, y en efectivo queda vacío (lo que venga
 * se descarta); algo tiene que cambiar; el motivo es obligatorio (5 a 300). El comprobante no tiene que ser único.
 *
 * Bloquea la caja primero (como CerrarCaja y RevisarCierre): la corrección no se cruza con el reenvío ni con la recepción.
 * Queda en Auditoría como EDITAR de la línea (quién, cuándo, valor anterior y nuevo del medio y del comprobante) con el motivo. El
 * permiso (EDITAR sobre COBROS) lo controla la ruta.
 */
final class CorregirComprobante
{
    public const NO_DEVUELTA = 'Solo se corrige un cobro de una caja devuelta por diferencias.';

    public const NO_ES_SU_CAJA = 'Solo el cajero de la caja corrige sus cobros.';

    public static function ejecutar(User $usuario, LineaCobro $linea, ?string $comprobante, string $motivo, ?int $medioPagoId = null): LineaCobro
    {
        $comprobante = trim((string) $comprobante);
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < RevertirCobro::MOTIVO_MINIMO || mb_strlen($motivo) > RevertirCobro::MOTIVO_MAXIMO) {
            throw new AccionRechazada('Indique el motivo de la corrección ('.RevertirCobro::MOTIVO_MINIMO.' a '.RevertirCobro::MOTIVO_MAXIMO.' caracteres).');
        }
        if (mb_strlen($comprobante) > Cobrar::REFERENCIA_MAXIMA) {
            throw new AccionRechazada('El comprobante no puede tener más de '.Cobrar::REFERENCIA_MAXIMA.' caracteres.');
        }

        return DB::transaction(function () use ($usuario, $linea, $comprobante, $motivo, $medioPagoId) {
            $cajaId = Cobro::query()->whereKey($linea->cobro_id)->value('caja_id');
            $caja = Caja::query()->whereKey($cajaId)->lockForUpdate()->firstOrFail();
            $cobro = Cobro::query()->lockForUpdate()->findOrFail($linea->cobro_id);
            $actual = LineaCobro::query()->with('medioPago')->lockForUpdate()->findOrFail($linea->id);
            if (! $caja->devuelta()) {
                throw new AccionRechazada(self::NO_DEVUELTA);
            }
            if ($caja->usuario_id !== $usuario->id) {
                throw new AccionRechazada(self::NO_ES_SU_CAJA);
            }
            if ($cobro->revertido()) {
                throw new AccionRechazada('El cobro está revertido: ya no se corrige.');
            }

            $medio = $actual->medioPago;
            if ($medioPagoId !== null && $medioPagoId !== $actual->medio_pago_id) {
                $medio = MedioPago::query()->find($medioPagoId);
                if ($medio === null || ! $medio->estaActivo()) {
                    throw new AccionRechazada('Elija un medio de pago activo.');
                }
            }
            if ($medio->esEfectivo()) {
                $comprobante = '';
            } elseif ($comprobante === '' && $medio->exigeComprobante()) {
                throw new AccionRechazada($medio->mensajeComprobanteFaltante());
            }
            $nuevo = $comprobante === '' ? null : $comprobante;
            if ($medio->id === $actual->medio_pago_id && $nuevo === $actual->referencia) {
                throw new AccionRechazada('No cambió nada: ni el medio ni el comprobante.');
            }

            $que = $medio->id === $actual->medio_pago_id ? 'del comprobante' : 'del medio de pago';
            Auditoria::conDetalle("Corrección {$que} del cobro {$cobro->id}. Motivo: {$motivo}", fn () => $actual->corregirPago($medio->id, $nuevo));
            $actual->setRelation('medioPago', $medio);

            return $actual;
        });
    }
}
