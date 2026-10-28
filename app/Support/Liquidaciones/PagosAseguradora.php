<?php

namespace App\Support\Liquidaciones;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\Liquidacion;
use App\Models\MedioPago;
use App\Models\PagoAseguradora;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los pagos de la aseguradora sobre una liquidación aprobada (docs/liquidaciones-aseguradoras.md): varios parciales, en guaraníes
 * enteros, con fecha (no futura), medio activo del catálogo y referencia opcional. La suma de los pagos vigentes no supera el total; el
 * saldo (total - pagos vigentes) se calcula. La liquidación pasa a PARCIAL o PAGADO según el saldo. Un pago mal cargado se
 * anula con motivo (nunca se borra) y el estado se recalcula. NO pasa por Caja: no toca cajas, cierres ni cobros.
 *
 * Queda en Auditoría: CREAR del pago y EDITAR de la liquidación ("Pago de la aseguradora"), ANULAR del pago con el motivo. Los
 * permisos (CREAR y DESACTIVAR sobre PAGOS_ASEGURADORAS: Facturación y convenios y el Administrador) los controla la ruta.
 */
final class PagosAseguradora
{
    public const REFERENCIA_MAXIMA = 100;

    public const NO_APROBADA = 'Solo se registran pagos de una liquidación aprobada que tiene saldo.';

    public static function registrar(User $usuario, Liquidacion $liquidacion, int $monto, Carbon $fecha, int $medioPagoId, ?string $referencia): PagoAseguradora
    {
        if ($monto < 1) {
            throw new AccionRechazada('El monto debe ser mayor que cero.');
        }
        if ($fecha->format('Y-m-d') > Fecha::hoy()->format('Y-m-d')) {
            throw new AccionRechazada('La fecha del pago no puede ser posterior a hoy.');
        }
        $referencia = trim((string) $referencia);

        return DB::transaction(function () use ($usuario, $liquidacion, $monto, $fecha, $medioPagoId, $referencia) {
            $actual = Liquidacion::query()->whereKey($liquidacion->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->aprobada()) {
                throw new AccionRechazada(self::NO_APROBADA);
            }
            if (! MedioPago::query()->whereKey($medioPagoId)->activos()->exists()) {
                throw new AccionRechazada('Elija un medio de pago activo.');
            }
            $saldo = $actual->saldo();
            if ($monto > $saldo) {
                throw new AccionRechazada("El pago supera el saldo de la liquidación ({$actual->formatear($saldo)}).");
            }

            return Auditoria::conDetalle("Pago de la aseguradora, liquidación N.º {$actual->id}", function () use ($usuario, $actual, $monto, $fecha, $medioPagoId, $referencia) {
                $pago = PagoAseguradora::create([
                    'liquidacion_id' => $actual->id, 'monto' => $monto, 'fecha' => $fecha->format('Y-m-d'), 'medio_pago_id' => $medioPagoId,
                    'referencia' => $referencia === '' ? null : mb_substr($referencia, 0, self::REFERENCIA_MAXIMA), 'usuario_id' => $usuario->id,
                ]);
                self::recalcular($actual);

                return $pago;
            });
        });
    }

    public static function anular(User $usuario, PagoAseguradora $pago, string $motivo): PagoAseguradora
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < FlujoLiquidacion::MOTIVO_MINIMO) {
            throw new AccionRechazada('Indique el motivo de la anulación (al menos '.FlujoLiquidacion::MOTIVO_MINIMO.' caracteres).');
        }
        $motivo = mb_substr($motivo, 0, FlujoLiquidacion::MOTIVO_MAXIMO);

        return DB::transaction(function () use ($usuario, $pago, $motivo) {
            $liquidacion = Liquidacion::query()->whereKey($pago->liquidacion_id)->lockForUpdate()->firstOrFail();
            $actual = PagoAseguradora::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();
            if ($actual->anulado()) {
                throw new AccionRechazada('Este pago ya está anulado.');
            }
            Auditoria::conDetalle("Pago anulado. Motivo: {$motivo}", function () use ($usuario, $actual, $liquidacion, $motivo) {
                $actual->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulado_en' => now(), 'anulado_por' => $usuario->id, 'motivo_anulacion' => $motivo])->save();
                self::recalcular($liquidacion);
            });
            $actual->unsetRelation('estado');

            return $actual;
        });
    }

    /** El estado según el saldo: sin pagos, APROBADO; con saldo, PARCIAL; sin saldo, PAGADO. */
    private static function recalcular(Liquidacion $liquidacion): void
    {
        $liquidacion->unsetRelation('estado');
        $pagado = $liquidacion->pagado();
        $estado = match (true) {
            $pagado === 0 => Estado::APROBADO,
            $pagado >= $liquidacion->totalEntero() => Estado::PAGADO,
            default => Estado::PARCIAL,
        };
        if ((int) $liquidacion->estado_id !== Estado::idDe($estado)) {
            $liquidacion->forceFill(['estado_id' => Estado::idDe($estado)])->save();
            $liquidacion->unsetRelation('estado');
        }
    }
}
