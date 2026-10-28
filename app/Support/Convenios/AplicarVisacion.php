<?php

namespace App\Support\Convenios;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\Prestacion;
use App\Models\User;
use App\Models\Visacion;
use App\Support\Auditoria;
use App\Support\Liquidaciones\BloqueoLiquidacion;
use App\Support\Repartos\RepartirPrestacion;
use Illuminate\Support\Facades\DB;

/**
 * "Aplicar visación" a una prestación ya creada (docs/convenios-seguros.md): la visación llegó tarde, se corrigió, o se la vuelve
 * particular. Es el ÚNICO caso en que se recalcula su snapshot (monto, tarifa usada, pagador y partes), con motivo; el estado anterior
 * queda en Auditoría (EDITAR con el antes y el después). La firma recibida, si había, vuelve a pedirse.
 *
 * - Solo sin cobro vigente (si ya se cobró como particular, primero se revierte el cobro, fase 3b) y fuera de toda liquidación no
 *   anulada (BloqueoLiquidacion, docs/liquidaciones-aseguradoras.md).
 * - La visación: VIGENTE, de la misma paciente, de la consulta o del turno de la prestación y con una línea para su procedimiento.
 *   Sin visación (null): queda particular.
 * - Si el total cambia, el reparto anterior se anula y se calcula otro sobre el total nuevo (RepartirPrestacion::reemplazar). Una
 *   pendiente de tarifar que ahora tiene tarifa se reparte como al tarifarla.
 * - Solo las prestaciones creadas desde el hito CONVENIOS.
 *
 * El permiso (EDITAR sobre VISACIONES: Recepción y Facturación y convenios) lo controla la ruta.
 */
final class AplicarVisacion
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 300;

    public const COBRADA = 'Esta prestación tiene un cobro vigente: revierta el cobro antes de aplicar la visación.';

    public static function ejecutar(User $usuario, Prestacion $prestacion, ?Visacion $visacion, string $motivo): Prestacion
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new AccionRechazada('Indique el motivo (al menos '.self::MOTIVO_MINIMO.' caracteres).');
        }
        $motivo = mb_substr($motivo, 0, self::MOTIVO_MAXIMO);

        return DB::transaction(function () use ($usuario, $prestacion, $visacion, $motivo) {
            $actual = Prestacion::query()->whereKey($prestacion->getKey())->lockForUpdate()->firstOrFail();
            self::verificar($actual);
            if ($visacion !== null) {
                $visacion = Visacion::query()->whereKey($visacion->getKey())->with('cobertura')->lockForUpdate()->firstOrFail();
                self::verificarVisacion($actual, $visacion);
            }

            $atributos = CalcularCobertura::atributos($actual, $visacion, particular: $visacion === null)
                ?? throw new AccionRechazada('No hay tarifa para ese procedimiento en la fecha del servicio. Cárguela en Tarifas o en el convenio.');
            if ($visacion !== null && $atributos['visacion_id'] === null) {
                // La cobertura o el convenio no estaban vigentes ese día.
                throw new AccionRechazada((string) $atributos['aviso_cobertura']);
            }

            $estabaPendiente = $actual->pendiente();
            $montoAnterior = $actual->monto === null ? null : (int) round((float) $actual->monto);
            $detalle = ($visacion ? "Aplicar visación N.º {$visacion->numero}" : 'Aplicar visación: particular').". Motivo: {$motivo}";

            Auditoria::conDetalle($detalle, fn () => $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::TARIFADO),
                ...$atributos,
                ...($estabaPendiente ? ['tarifada_en' => now(), 'tarifada_por' => $usuario->id] : []),
                'visacion_aplicada_en' => now(),
                'visacion_aplicada_por' => $usuario->id,
                'motivo_aplicacion' => $motivo,
                'firma_recibida_en' => null,
                'firma_recibida_por' => null,
            ])->save());
            $actual->unsetRelation('estado');

            // El reparto sigue sobre el total: si cambió, el anterior se anula y se calcula otro (docs/reparto-economico.md).
            if ($estabaPendiente) {
                RepartirPrestacion::alFijarMonto($actual);
            } elseif ($montoAnterior !== $atributos['monto']) {
                RepartirPrestacion::reemplazar($usuario, $actual, "Reemplazado al aplicar la visación (total de {$actual->moneda->formatear($montoAnterior)} a {$actual->moneda->formatear($atributos['monto'])}).");
            }

            return $actual;
        });
    }

    /** Lo que impide aplicar una visación a la prestación, o null si se puede. */
    public static function impedimento(Prestacion $prestacion): ?string
    {
        return match (true) {
            ! CalcularCobertura::corresponde($prestacion) => 'Esta prestación es anterior a los convenios: no se recalcula.',
            $prestacion->anulada() => 'Esta prestación está anulada.',
            $prestacion->procedimiento_id === null => 'La prestación no tiene procedimiento.',
            $prestacion->cobroVigente()->exists() => self::COBRADA,
            default => BloqueoLiquidacion::impedimento($prestacion),
        };
    }

    private static function verificar(Prestacion $prestacion): void
    {
        if (($motivo = self::impedimento($prestacion)) !== null) {
            throw new AccionRechazada($motivo);
        }
    }

    private static function verificarVisacion(Prestacion $prestacion, Visacion $visacion): void
    {
        $deLaPrestacion = (int) $visacion->consulta_id === (int) $prestacion->consulta_id
            || ($visacion->turno_id !== null && (int) $visacion->turno_id === (int) $prestacion->turno_id);
        if (! $visacion->vigente() || (int) $visacion->cobertura->paciente_id !== (int) $prestacion->paciente_id || ! $deLaPrestacion) {
            throw new AccionRechazada('La visación no está vigente o no es de esta consulta ni de su turno.');
        }
        if (! $visacion->lineas()->where('procedimiento_id', $prestacion->procedimiento_id)->exists()) {
            throw new AccionRechazada("La visación N.º {$visacion->numero} no incluye el procedimiento de esta prestación.");
        }
    }
}
