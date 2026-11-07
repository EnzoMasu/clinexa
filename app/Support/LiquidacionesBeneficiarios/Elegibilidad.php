<?php

namespace App\Support\LiquidacionesBeneficiarios;

use App\Models\Estado;
use App\Models\LineaLiquidacionBeneficiario;
use App\Models\LineaReparto;
use App\Models\LiquidacionBeneficiario;
use App\Models\Prestacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Qué le corresponde liquidar a cada beneficiario (docs/liquidacion-beneficiarios.md), sobre las líneas del reparto:
 *
 * - DEVENGADA: una línea de un reparto REPARTIDO (no anulado) de una prestación no anulada.
 * - PERCIBIDA (liquidable): devengada y con las dos partes cumplidas. La parte de la paciente (si es mayor que cero) con un cobro
 *   vigente; la de la aseguradora (si es mayor que cero) en una liquidación a la aseguradora PAGADA. Los pagos parciales de la
 *   aseguradora no se asignan por prestación: mientras su liquidación no esté pagada del todo, ninguna de sus prestaciones es percibida.
 *   La línea entra entera (con copago, cuando las dos partes están cumplidas).
 * - LIBRE: sin una línea ORDINARIA no liberada pendiente de compensar. Una ordinaria queda compensada por un AJUSTE no liberado de
 *   una liquidación aprobada; entonces la línea del reparto puede volver a entrar (cobro revertido y vuelto a cobrar).
 *
 * Se liquidan solo las líneas PROFESIONAL y EQUIPO con monto mayor que cero: la clínica no se liquida (se informa) y la línea en
 * cero de un funcionario (acuerdo general de 0 %, sin porcentaje) se ignora.
 */
final class Elegibilidad
{
    public const SIN_COBRO = 'Falta cobrar la parte de la paciente.';

    public const ASEGURADORA_SIN_LIQUIDAR = 'Lo de la aseguradora todavía no está en una liquidación.';

    public const ASEGURADORA_SIN_PAGAR = 'La liquidación de la aseguradora todavía no está pagada del todo.';

    /** Las líneas de reparto devengadas (con `p` = la prestación y `rp` = el reparto en el join). */
    public static function devengadas(): Builder
    {
        return LineaReparto::query()->select('lineas_reparto.*')
            ->join('repartos_prestacion as rp', 'rp.id', '=', 'lineas_reparto.reparto_prestacion_id')
            ->join('prestaciones as p', 'p.id', '=', 'rp.prestacion_id')
            ->whereNull('rp.anulado_en')
            ->where('rp.estado_id', Estado::idDe(Estado::REPARTIDO))
            ->where('p.estado_id', '!=', Estado::idDe(Estado::ANULADO));
    }

    /** Las devengadas con las dos partes cumplidas. */
    public static function percibidas(): Builder
    {
        return self::conPartesCumplidas(self::devengadas());
    }

    public static function conPartesCumplidas(Builder $query): Builder
    {
        $pagado = Estado::idDe(Estado::PAGADO);

        return $query
            ->where(fn (Builder $q) => $q->whereRaw('COALESCE(p.monto_paciente, p.monto, 0) <= 0')
                ->orWhereExists(fn (QueryBuilder $s) => $s->select(DB::raw(1))->from('cobro_prestaciones as cp')
                    ->whereColumn('cp.prestacion_id', 'p.id')->whereNull('cp.revertido_en')))
            ->where(fn (Builder $q) => $q->whereNull('p.pagador')->orWhere('p.pagador', '!=', Prestacion::ASEGURADORA)
                ->orWhereRaw('COALESCE(p.monto_aseguradora, 0) <= 0')
                ->orWhereExists(fn (QueryBuilder $s) => $s->select(DB::raw(1))->from('lineas_liquidacion as ll')
                    ->join('liquidaciones as la', 'la.id', '=', 'll.liquidacion_id')
                    ->whereColumn('ll.prestacion_id', 'p.id')->whereNull('ll.liberada_en')->where('la.estado_id', $pagado)));
    }

    /** Las líneas de ese beneficiario que se liquidan (PROFESIONAL o EQUIPO, con monto mayor que cero). */
    public static function delBeneficiario(Builder $query, string $tipo, int $id): Builder
    {
        return $query->where('lineas_reparto.beneficiario', $tipo === LiquidacionBeneficiario::PROFESIONAL ? LineaReparto::PROFESIONAL : LineaReparto::EQUIPO)
            ->where($tipo === LiquidacionBeneficiario::PROFESIONAL ? 'lineas_reparto.profesional_id' : 'lineas_reparto.proveedor_id', $id)
            ->where('lineas_reparto.monto', '>', 0);
    }

    /** Solo las de los beneficiarios que se liquidan (profesional y dueño de equipo), con monto mayor que cero. */
    public static function liquidables(Builder $query): Builder
    {
        return $query->whereIn('lineas_reparto.beneficiario', [LineaReparto::PROFESIONAL, LineaReparto::EQUIPO])->where('lineas_reparto.monto', '>', 0);
    }

    /** Sin una ordinaria no liberada pendiente de compensar (por un ajuste no liberado de una liquidación aprobada). */
    public static function libres(Builder $query): Builder
    {
        return $query->whereNotExists(fn (QueryBuilder $s) => $s->select(DB::raw(1))->from('lineas_liquidacion_beneficiario as o')
            ->whereColumn('o.linea_reparto_id', 'lineas_reparto.id')
            ->where('o.tipo', LineaLiquidacionBeneficiario::ORDINARIA)->whereNull('o.liberada_en')
            ->whereNotExists(fn (QueryBuilder $a) => $a->select(DB::raw(1))->from('lineas_liquidacion_beneficiario as aj')
                ->join('liquidaciones_beneficiario as lb', 'lb.id', '=', 'aj.liquidacion_beneficiario_id')
                ->whereColumn('aj.ajusta_linea_id', 'o.id')->where('aj.tipo', LineaLiquidacionBeneficiario::AJUSTE)->whereNull('aj.liberada_en')
                ->whereNotNull('lb.aprobada_en')->whereNull('lb.anulada_en')));
    }

    public static function esPercibida(int $lineaRepartoId): bool
    {
        return self::percibidas()->where('lineas_reparto.id', $lineaRepartoId)->exists();
    }

    /** Por qué una línea devengada todavía no es percibida (o null si lo es). */
    public static function motivoNoPercibida(Prestacion $prestacion): ?string
    {
        $paciente = $prestacion->montoPaciente() ?? 0;
        if ($paciente > 0 && ! $prestacion->cobroVigente()->exists()) {
            return self::SIN_COBRO;
        }
        if ($prestacion->deAseguradora() && (int) round((float) $prestacion->monto_aseguradora) > 0) {
            $linea = $prestacion->lineaLiquidacion()->with('liquidacion.estado')->first();
            if ($linea === null) {
                return self::ASEGURADORA_SIN_LIQUIDAR;
            }
            if (! $linea->liquidacion->tieneEstado(Estado::PAGADO)) {
                return self::ASEGURADORA_SIN_PAGAR." (liquidación N.º {$linea->liquidacion_id}: {$linea->liquidacion->etiquetaEstado()})";
            }
        }

        return null;
    }

    /** Por qué una línea ya liquidada dejó de corresponder (para el ajuste o la marca), o null si sigue correspondiendo. */
    public static function motivoDejoDeCorresponder(LineaReparto $linea): ?string
    {
        $linea->loadMissing('reparto.prestacion');
        $reparto = $linea->reparto;
        $prestacion = $reparto->prestacion;
        if ($reparto->anulado()) {
            return mb_substr('Reparto anulado: '.$reparto->motivo_anulacion, 0, 300);
        }
        if ($prestacion->anulada()) {
            return mb_substr('Prestación anulada: '.$prestacion->motivo_anulacion, 0, 300);
        }
        $motivo = self::motivoNoPercibida($prestacion);
        if ($motivo === self::SIN_COBRO) {
            return 'El cobro a la paciente se revirtió (o se anuló su factura).';
        }

        return $motivo;
    }
}
