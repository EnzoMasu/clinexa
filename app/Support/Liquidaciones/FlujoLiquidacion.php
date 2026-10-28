<?php

namespace App\Support\Liquidaciones;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\LineaLiquidacion;
use App\Models\Liquidacion;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * El flujo de una liquidación (docs/liquidaciones-aseguradoras.md):
 *
 * - En preparación, Facturación y convenios quita líneas con motivo (la prestación queda libre para la siguiente; el total baja) y la
 *   "Envía a aprobación" (con al menos una línea).
 * - Gerencia (o el Administrador) la aprueba (queda bloqueada y "pendiente de facturar") o la devuelve con nota (vuelve a EN_PREPARACION).
 *   Nadie aprueba ni devuelve una liquidación que armó él mismo.
 * - Se anula con motivo, solo sin pagos vigentes: sus prestaciones quedan libres. La aprobada, solo quien aprueba (lo controla la ruta).
 *
 * Todo queda en Auditoría con su detalle. Los permisos (EDITAR y DESACTIVAR sobre LIQUIDACIONES; EDITAR sobre APROBACION_LIQUIDACIONES)
 * los controla la ruta.
 */
final class FlujoLiquidacion
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    public const PROPIA = 'No puede aprobar ni devolver una liquidación que armó usted.';

    public const CON_PAGOS = 'Esta liquidación tiene pagos vigentes: anule los pagos antes de anularla.';

    public static function quitarLinea(User $usuario, LineaLiquidacion $linea, string $motivo): Liquidacion
    {
        $motivo = self::texto($motivo, 'Indique el motivo para quitar la prestación');

        return DB::transaction(function () use ($usuario, $linea, $motivo) {
            $liquidacion = self::bloqueada($linea->liquidacion_id);
            if (! $liquidacion->enPreparacion()) {
                throw new AccionRechazada('Solo se quitan prestaciones de una liquidación en preparación.');
            }
            $actual = LineaLiquidacion::query()->whereKey($linea->getKey())->where('liquidacion_id', $liquidacion->id)->lockForUpdate()->firstOrFail();
            if ($actual->liberada()) {
                throw new AccionRechazada('Esa prestación ya fue quitada de la liquidación.');
            }

            Auditoria::conDetalle("Quitada de la liquidación N.º {$liquidacion->id}. Motivo: {$motivo}", function () use ($usuario, $actual, $liquidacion, $motivo) {
                $actual->forceFill(['liberacion' => LineaLiquidacion::QUITADA, 'liberada_en' => now(), 'liberada_por' => $usuario->id, 'motivo_liberacion' => $motivo])->save();
                $liquidacion->forceFill(['total' => self::sumaDeLineas($liquidacion)])->save();
            });

            return $liquidacion;
        });
    }

    public static function enviar(User $usuario, Liquidacion $liquidacion): Liquidacion
    {
        return DB::transaction(function () use ($usuario, $liquidacion) {
            $actual = self::bloqueada($liquidacion->id);
            if (! $actual->enPreparacion()) {
                throw new AccionRechazada('Esta liquidación no está en preparación.');
            }
            if (! $actual->lineas()->whereNull('liberada_en')->exists()) {
                throw new AccionRechazada('La liquidación no tiene prestaciones: no se puede enviar. Anúlela si no corresponde.');
            }
            Auditoria::conDetalle('Enviada a aprobación', fn () => $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::PENDIENTE_APROBACION), 'enviada_en' => now(), 'enviada_por' => $usuario->id,
            ])->save());

            return self::refrescar($actual);
        });
    }

    public static function aprobar(User $revisor, Liquidacion $liquidacion): Liquidacion
    {
        return DB::transaction(function () use ($revisor, $liquidacion) {
            $actual = self::paraRevisar($revisor, $liquidacion);
            Auditoria::conDetalle('Liquidación aprobada', fn () => $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::APROBADO), 'aprobada_en' => now(), 'aprobada_por' => $revisor->id,
                'facturacion' => Liquidacion::PENDIENTE_DE_FACTURAR,
            ])->save());

            return self::refrescar($actual);
        });
    }

    public static function devolver(User $revisor, Liquidacion $liquidacion, string $nota): Liquidacion
    {
        $nota = self::texto($nota, 'Indique qué hay que corregir');

        return DB::transaction(function () use ($revisor, $liquidacion, $nota) {
            $actual = self::paraRevisar($revisor, $liquidacion);
            Auditoria::conDetalle("Liquidación devuelta. Nota: {$nota}", fn () => $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::EN_PREPARACION), 'devuelta_en' => now(), 'devuelta_por' => $revisor->id, 'nota_devolucion' => $nota,
            ])->save());

            return self::refrescar($actual);
        });
    }

    public static function anular(User $usuario, Liquidacion $liquidacion, string $motivo): Liquidacion
    {
        $motivo = self::texto($motivo, 'Indique el motivo de la anulación');

        return DB::transaction(function () use ($usuario, $liquidacion, $motivo) {
            $actual = self::bloqueada($liquidacion->id);
            if ($actual->anulada()) {
                throw new AccionRechazada('Esta liquidación ya está anulada.');
            }
            if ($actual->pagosVigentes()->exists()) {
                throw new AccionRechazada(self::CON_PAGOS);
            }
            $ahora = now();
            Auditoria::conDetalle("Liquidación anulada. Motivo: {$motivo}", function () use ($usuario, $actual, $motivo, $ahora) {
                $actual->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_en' => $ahora, 'anulada_por' => $usuario->id, 'motivo_anulacion' => $motivo])->save();
                // Sus prestaciones quedan libres para la siguiente.
                foreach ($actual->lineas()->whereNull('liberada_en')->lockForUpdate()->get() as $linea) {
                    $linea->forceFill(['liberacion' => LineaLiquidacion::ANULADA, 'liberada_en' => $ahora, 'liberada_por' => $usuario->id,
                        'motivo_liberacion' => "Liquidación anulada: {$motivo}"])->save();
                }
            });

            return self::refrescar($actual);
        });
    }

    /** La suma exacta de las líneas no quitadas, en guaraníes enteros. */
    public static function sumaDeLineas(Liquidacion $liquidacion): int
    {
        return (int) $liquidacion->lineas()->whereNull('liberada_en')->get()->sum(fn (LineaLiquidacion $linea) => $linea->montoEntero());
    }

    private static function paraRevisar(User $revisor, Liquidacion $liquidacion): Liquidacion
    {
        $actual = self::bloqueada($liquidacion->id);
        if (! $actual->pendienteDeAprobacion()) {
            throw new AccionRechazada('Esta liquidación no está pendiente de aprobación.');
        }
        if ((int) $actual->armada_por === (int) $revisor->id) {
            throw new AccionRechazada(self::PROPIA);
        }

        return $actual;
    }

    private static function bloqueada(int $id): Liquidacion
    {
        return Liquidacion::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private static function refrescar(Liquidacion $liquidacion): Liquidacion
    {
        $liquidacion->unsetRelation('estado');

        return $liquidacion;
    }

    private static function texto(string $texto, string $mensaje): string
    {
        $texto = trim($texto);
        if (mb_strlen($texto) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada("{$mensaje} (al menos ".self::MOTIVO_MINIMO.' caracteres).');
        }

        return mb_substr($texto, 0, self::MOTIVO_MAXIMO);
    }
}
