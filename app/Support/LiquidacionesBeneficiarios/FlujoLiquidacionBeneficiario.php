<?php

namespace App\Support\LiquidacionesBeneficiarios;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\LineaLiquidacionBeneficiario;
use App\Models\LiquidacionBeneficiario;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El flujo de la liquidación a un beneficiario (docs/liquidacion-beneficiarios.md):
 *
 * - En preparación, Facturación y convenios (o Gerencia) quita líneas con motivo (postergar: la línea queda libre para la siguiente) y
 *   la envía a aprobación, con al menos una línea y sin líneas MARCADAS.
 * - Una línea queda marcada si dejó de corresponder mientras la liquidación está abierta: la ordinaria cuyo cobro se revirtió (o su
 *   factura se anuló, o se aplicó una visación, o se anuló la prestación), y el ajuste cuya prestación volvió a cobrarse. Hay que
 *   quitarla antes de enviar o de aprobar (pendiente de aprobación: se devuelve y se quita).
 * - Gerencia la aprueba (bloqueada) o la devuelve con nota. Nadie aprueba ni devuelve la que armó: la de Gerencia la aprueba el
 *   Administrador.
 * - Se anula con motivo, sin pagos vigentes y sin que otra liquidación dependa de ella (un ajuste de sus líneas, una línea que volvió a
 *   entrar después de un ajuste suyo, o su saldo en contra ya trasladado). La aprobada, solo quien aprueba (lo controla la ruta).
 *
 * Todo queda en Auditoría con su detalle. Los permisos los controla la ruta.
 */
final class FlujoLiquidacionBeneficiario
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    public const PROPIA = 'No puede aprobar ni devolver una liquidación que armó usted.';

    public const CON_PAGOS = 'Esta liquidación tiene pagos vigentes: anule los pagos antes de anularla.';

    public static function conMarcadas(int $cantidad, bool $pendiente): string
    {
        $lineas = $cantidad === 1 ? 'Hay 1 línea marcada (dejó de corresponder)' : "Hay {$cantidad} líneas marcadas (dejaron de corresponder)";

        return $pendiente ? "{$lineas}: devuelva la liquidación para que se quiten antes de aprobarla." : "{$lineas}: quítelas antes de enviar la liquidación.";
    }

    /**
     * Las líneas no liberadas de una liquidación abierta que dejaron de corresponder, con el motivo.
     *
     * @return Collection<int, array{linea: LineaLiquidacionBeneficiario, motivo: string}>
     */
    public static function marcadas(LiquidacionBeneficiario $liquidacion): Collection
    {
        if (! $liquidacion->abierta()) {
            return collect();
        }
        $lineas = $liquidacion->lineas()->whereNull('liberada_en')->whereIn('tipo', [LineaLiquidacionBeneficiario::ORDINARIA, LineaLiquidacionBeneficiario::AJUSTE])->get();
        if ($lineas->isEmpty()) {
            return collect();
        }
        $percibidas = Elegibilidad::percibidas()->whereIn('lineas_reparto.id', $lineas->pluck('linea_reparto_id'))->pluck('lineas_reparto.id')->flip();

        return $lineas->map(function (LineaLiquidacionBeneficiario $linea) use ($percibidas) {
            $corresponde = $percibidas->has($linea->linea_reparto_id);
            if ($linea->ordinaria() && ! $corresponde) {
                return ['linea' => $linea, 'motivo' => Elegibilidad::motivoDejoDeCorresponder($linea->lineaReparto) ?? 'Dejó de corresponder.'];
            }
            if ($linea->ajuste() && $corresponde) {
                return ['linea' => $linea, 'motivo' => 'La prestación volvió a corresponder (se cobró de nuevo): este ajuste ya no va.'];
            }

            return null;
        })->filter()->values();
    }

    public static function quitarLinea(User $usuario, LineaLiquidacionBeneficiario $linea, string $motivo): LiquidacionBeneficiario
    {
        $motivo = self::texto($motivo, 'Indique el motivo para quitar la línea');

        return DB::transaction(function () use ($usuario, $linea, $motivo) {
            $liquidacion = self::bloqueada($linea->liquidacion_beneficiario_id);
            if (! $liquidacion->enPreparacion()) {
                throw new AccionRechazada('Solo se quitan líneas de una liquidación en preparación.');
            }
            $actual = LineaLiquidacionBeneficiario::query()->whereKey($linea->getKey())->where('liquidacion_beneficiario_id', $liquidacion->id)->lockForUpdate()->firstOrFail();
            if ($actual->liberada()) {
                throw new AccionRechazada('Esa línea ya fue quitada de la liquidación.');
            }

            Auditoria::conDetalle("Quitada de la liquidación a beneficiario N.º {$liquidacion->id}. Motivo: {$motivo}", function () use ($usuario, $actual, $liquidacion, $motivo) {
                $actual->forceFill(['liberacion' => LineaLiquidacionBeneficiario::QUITADA, 'liberada_en' => now(), 'liberada_por' => $usuario->id, 'motivo_liberacion' => $motivo])->save();
                $liquidacion->forceFill(['total' => self::sumaDeLineas($liquidacion)])->save();
            });

            return self::refrescar($liquidacion);
        });
    }

    public static function enviar(User $usuario, LiquidacionBeneficiario $liquidacion): LiquidacionBeneficiario
    {
        return DB::transaction(function () use ($usuario, $liquidacion) {
            $actual = self::bloqueada($liquidacion->id);
            if (! $actual->enPreparacion()) {
                throw new AccionRechazada('Esta liquidación no está en preparación.');
            }
            if (! $actual->lineas()->whereNull('liberada_en')->exists()) {
                throw new AccionRechazada('La liquidación no tiene líneas: no se puede enviar. Anúlela si no corresponde.');
            }
            if (($marcadas = self::marcadas($actual)->count()) > 0) {
                throw new AccionRechazada(self::conMarcadas($marcadas, false));
            }
            Auditoria::conDetalle('Enviada a aprobación', fn () => $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::PENDIENTE_APROBACION), 'enviada_en' => now(), 'enviada_por' => $usuario->id,
            ])->save());

            return self::refrescar($actual);
        });
    }

    public static function aprobar(User $revisor, LiquidacionBeneficiario $liquidacion): LiquidacionBeneficiario
    {
        return DB::transaction(function () use ($revisor, $liquidacion) {
            $actual = self::paraRevisar($revisor, $liquidacion);
            if (($marcadas = self::marcadas($actual)->count()) > 0) {
                throw new AccionRechazada(self::conMarcadas($marcadas, true));
            }
            Auditoria::conDetalle('Liquidación a beneficiario aprobada', fn () => $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::APROBADO), 'aprobada_en' => now(), 'aprobada_por' => $revisor->id,
            ])->save());

            return self::refrescar($actual);
        });
    }

    public static function devolver(User $revisor, LiquidacionBeneficiario $liquidacion, string $nota): LiquidacionBeneficiario
    {
        $nota = self::texto($nota, 'Indique qué hay que corregir');

        return DB::transaction(function () use ($revisor, $liquidacion, $nota) {
            $actual = self::paraRevisar($revisor, $liquidacion);
            Auditoria::conDetalle("Liquidación a beneficiario devuelta. Nota: {$nota}", fn () => $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::EN_PREPARACION), 'devuelta_en' => now(), 'devuelta_por' => $revisor->id, 'nota_devolucion' => $nota,
            ])->save());

            return self::refrescar($actual);
        });
    }

    /** Por qué no se puede anular (sin mirar el permiso), o null. */
    public static function impedimentoParaAnular(LiquidacionBeneficiario $liquidacion): ?string
    {
        if ($liquidacion->anulada()) {
            return 'Esta liquidación ya está anulada.';
        }
        if ($liquidacion->pagosVigentes()->exists()) {
            return self::CON_PAGOS;
        }
        $ids = $liquidacion->lineas()->whereNull('liberada_en')->pluck('id');
        $ajuste = LineaLiquidacionBeneficiario::query()->whereIn('ajusta_linea_id', $ids)->where('tipo', LineaLiquidacionBeneficiario::AJUSTE)
            ->whereNull('liberada_en')->value('liquidacion_beneficiario_id');
        if ($ajuste !== null) {
            return "Tiene líneas ajustadas en la liquidación N.º {$ajuste}: anule aquella primero.";
        }
        $saldo = LineaLiquidacionBeneficiario::query()->where('saldo_de_liquidacion_id', $liquidacion->id)->where('tipo', LineaLiquidacionBeneficiario::SALDO_ANTERIOR)
            ->whereNull('liberada_en')->value('liquidacion_beneficiario_id');
        if ($saldo !== null) {
            return "Su saldo en contra ya pasó a la liquidación N.º {$saldo}: anule aquella primero.";
        }
        foreach ($liquidacion->lineas()->whereNull('liberada_en')->where('tipo', LineaLiquidacionBeneficiario::AJUSTE)->with('ajustaA')->get() as $ajusteLinea) {
            $volvio = LineaLiquidacionBeneficiario::query()->where('linea_reparto_id', $ajusteLinea->linea_reparto_id)
                ->where('tipo', LineaLiquidacionBeneficiario::ORDINARIA)->whereNull('liberada_en')->where('vez', '>', (int) $ajusteLinea->ajustaA->vez)
                ->value('liquidacion_beneficiario_id');
            if ($volvio !== null) {
                return "Una prestación ajustada aquí volvió a liquidarse en la N.º {$volvio}: anule aquella primero.";
            }
        }

        return null;
    }

    public static function anular(User $usuario, LiquidacionBeneficiario $liquidacion, string $motivo): LiquidacionBeneficiario
    {
        $motivo = self::texto($motivo, 'Indique el motivo de la anulación');

        return DB::transaction(function () use ($usuario, $liquidacion, $motivo) {
            $actual = self::bloqueada($liquidacion->id);
            if (($impedimento = self::impedimentoParaAnular($actual)) !== null) {
                throw new AccionRechazada($impedimento);
            }
            $ahora = now();
            Auditoria::conDetalle("Liquidación a beneficiario anulada. Motivo: {$motivo}", function () use ($usuario, $actual, $motivo, $ahora) {
                $actual->forceFill(['estado_id' => Estado::idDe(Estado::ANULADO), 'anulada_en' => $ahora, 'anulada_por' => $usuario->id, 'motivo_anulacion' => $motivo])->save();
                foreach ($actual->lineas()->whereNull('liberada_en')->lockForUpdate()->get() as $linea) {
                    $linea->forceFill(['liberacion' => LineaLiquidacionBeneficiario::ANULADA, 'liberada_en' => $ahora, 'liberada_por' => $usuario->id,
                        'motivo_liberacion' => mb_substr("Liquidación anulada: {$motivo}", 0, self::MOTIVO_MAXIMO + 30)])->save();
                }
            });

            return self::refrescar($actual);
        });
    }

    /** La suma exacta de las líneas no quitadas, en guaraníes enteros (puede ser negativa). */
    public static function sumaDeLineas(LiquidacionBeneficiario $liquidacion): int
    {
        return (int) $liquidacion->lineas()->whereNull('liberada_en')->get()->sum(fn (LineaLiquidacionBeneficiario $linea) => $linea->montoEntero());
    }

    private static function paraRevisar(User $revisor, LiquidacionBeneficiario $liquidacion): LiquidacionBeneficiario
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

    private static function bloqueada(int $id): LiquidacionBeneficiario
    {
        return LiquidacionBeneficiario::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private static function refrescar(LiquidacionBeneficiario $liquidacion): LiquidacionBeneficiario
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
