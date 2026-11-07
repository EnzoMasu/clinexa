<?php

namespace App\Support\LiquidacionesBeneficiarios;

use App\Models\LineaLiquidacionBeneficiario;
use App\Models\LineaReparto;
use App\Models\LiquidacionBeneficiario;
use App\Models\PagoBeneficiario;
use App\Models\Profesional;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El servicio de consulta único del reparto por beneficiario y período (docs/liquidacion-beneficiarios.md), para la pestaña "Resumen
 * por período" y para los informes del objetivo 14 (14.5, 14.6), que deben usarlo en vez de rehacer las cuentas. Por beneficiario —la
 * clínica, cada profesional y cada dueño de equipo—, en guaraníes enteros:
 *
 * - devengado: sus líneas del reparto vigente de prestaciones no anuladas con fecha de servicio en el período;
 * - percibido: de esas, las que tienen las dos partes cumplidas (cobrada la paciente; la aseguradora, con su liquidación pagada);
 * - liquidable: lo percibido todavía sin liquidar (libre). No aplica a la clínica (no se liquida);
 * - liquidado: lo que está en liquidaciones aprobadas (ordinarias y ajustes en contra), por la fecha de servicio de la línea;
 * - pagado: los pagos vigentes con fecha en el período;
 * - saldo: lo que falta pagar hoy de sus liquidaciones aprobadas con corte hasta el fin del período.
 *
 * La parte de la clínica NO es su ganancia (faltan gastos y sueldos): eso es del objetivo 14. La prestación no tiene sucursal propia:
 * el informe por sucursal también es del objetivo 14.
 */
final class ResumenBeneficiarios
{
    public const CLINICA = 'CLINICA';

    /**
     * @return Collection<int, array{clave: string, tipo: string, beneficiario: string, devengado: int, percibido: int, liquidable: ?int, liquidado: ?int, pagado: ?int, saldo: ?int}>
     */
    public static function periodo(Carbon $desde, Carbon $hasta): Collection
    {
        [$d, $h] = [$desde->format('Y-m-d'), $hasta->format('Y-m-d')];
        $enPeriodo = fn (Builder $q) => $q->whereDate('p.fecha_servicio', '>=', $d)->whereDate('p.fecha_servicio', '<=', $h);

        $devengado = self::porClave($enPeriodo(Elegibilidad::devengadas()));
        $percibido = self::porClave($enPeriodo(Elegibilidad::percibidas()));
        $liquidable = self::porClave($enPeriodo(Elegibilidad::libres(Elegibilidad::liquidables(Elegibilidad::percibidas()))));

        $liquidado = LineaLiquidacionBeneficiario::query()
            ->join('liquidaciones_beneficiario as lb', 'lb.id', '=', 'lineas_liquidacion_beneficiario.liquidacion_beneficiario_id')
            ->whereNotNull('lb.aprobada_en')->whereNull('lb.anulada_en')->whereNull('lineas_liquidacion_beneficiario.liberada_en')
            ->whereIn('lineas_liquidacion_beneficiario.tipo', [LineaLiquidacionBeneficiario::ORDINARIA, LineaLiquidacionBeneficiario::AJUSTE])
            ->whereDate('lineas_liquidacion_beneficiario.fecha_servicio', '>=', $d)->whereDate('lineas_liquidacion_beneficiario.fecha_servicio', '<=', $h)
            ->groupBy('lb.beneficiario', 'lb.profesional_id', 'lb.proveedor_id')
            ->select([DB::raw('lb.beneficiario as b'), DB::raw('lb.profesional_id as pr'), DB::raw('lb.proveedor_id as pv'), DB::raw('SUM(lineas_liquidacion_beneficiario.monto) as total')])
            ->toBase()->get()->mapWithKeys(fn ($fila) => self::fila($fila));

        $pagado = PagoBeneficiario::query()->join('liquidaciones_beneficiario as lb', 'lb.id', '=', 'pagos_beneficiario.liquidacion_beneficiario_id')
            ->whereNull('pagos_beneficiario.anulado_en')->whereDate('pagos_beneficiario.fecha', '>=', $d)->whereDate('pagos_beneficiario.fecha', '<=', $h)
            ->groupBy('lb.beneficiario', 'lb.profesional_id', 'lb.proveedor_id')
            ->select([DB::raw('lb.beneficiario as b'), DB::raw('lb.profesional_id as pr'), DB::raw('lb.proveedor_id as pv'), DB::raw('SUM(pagos_beneficiario.monto) as total')])
            ->toBase()->get()->mapWithKeys(fn ($fila) => self::fila($fila));

        $saldo = LiquidacionBeneficiario::query()->aprobadas()->whereDate('fecha_corte', '<=', $h)->withSum('pagosVigentes', 'monto')->get()
            ->groupBy(fn (LiquidacionBeneficiario $l) => self::clave($l->beneficiario, $l->profesional_id, $l->proveedor_id))
            ->map(fn ($liquidaciones) => (int) $liquidaciones->sum(fn (LiquidacionBeneficiario $l) => $l->saldo()));

        $claves = collect([...$devengado->keys(), ...$liquidado->keys(), ...$pagado->keys(), ...$saldo->keys()])->unique();
        $nombres = self::nombres($claves);

        return $claves->map(function (string $clave) use ($devengado, $percibido, $liquidable, $liquidado, $pagado, $saldo, $nombres) {
            $clinica = $clave === self::CLINICA;

            return [
                'clave' => $clave,
                'tipo' => $clinica ? 'Clínica' : LineaReparto::ETIQUETAS[str_starts_with($clave, 'PROFESIONAL') ? LineaReparto::PROFESIONAL : LineaReparto::EQUIPO],
                'beneficiario' => $clinica ? 'Clínica' : ($nombres[$clave] ?? $clave),
                'devengado' => $devengado[$clave] ?? 0,
                'percibido' => $percibido[$clave] ?? 0,
                'liquidable' => $clinica ? null : ($liquidable[$clave] ?? 0),
                'liquidado' => $clinica ? null : ($liquidado[$clave] ?? 0),
                'pagado' => $clinica ? null : ($pagado[$clave] ?? 0),
                'saldo' => $clinica ? null : ($saldo[$clave] ?? 0),
            ];
        })->sortBy(fn (array $fila) => [$fila['clave'] === self::CLINICA ? 0 : 1, $fila['tipo'], $fila['beneficiario']])->values();
    }

    /** "CLINICA", "PROFESIONAL:5" o "EQUIPO:7" (el dueño). */
    public static function clave(string $beneficiario, ?int $profesionalId, ?int $proveedorId): string
    {
        return match ($beneficiario) {
            LineaReparto::PROFESIONAL => 'PROFESIONAL:'.$profesionalId,
            LineaReparto::EQUIPO => 'EQUIPO:'.$proveedorId,
            default => self::CLINICA,
        };
    }

    /** @return Collection<string, int> */
    private static function porClave(Builder $query): Collection
    {
        return $query->reorder()->groupBy('lineas_reparto.beneficiario', 'lineas_reparto.profesional_id', 'lineas_reparto.proveedor_id')
            ->select([DB::raw('lineas_reparto.beneficiario as b'), DB::raw('lineas_reparto.profesional_id as pr'), DB::raw('lineas_reparto.proveedor_id as pv'),
                DB::raw('SUM(lineas_reparto.monto) as total')])
            ->toBase()->get()->mapWithKeys(fn ($fila) => self::fila($fila));
    }

    /** Una fila agrupada => [clave => total entero]. */
    private static function fila(object $fila): array
    {
        return [self::clave((string) $fila->b, $fila->pr === null ? null : (int) $fila->pr, $fila->pv === null ? null : (int) $fila->pv) => (int) round((float) $fila->total)];
    }

    private static function nombres(Collection $claves): array
    {
        $ids = fn (string $prefijo) => $claves->filter(fn ($c) => str_starts_with($c, $prefijo.':'))->map(fn ($c) => (int) substr($c, strlen($prefijo) + 1))->all();
        $nombres = [];
        foreach (Profesional::query()->whereIn('id', $ids('PROFESIONAL'))->with('persona')->get() as $p) {
            $nombres['PROFESIONAL:'.$p->id] = $p->persona->nombre_completo;
        }
        foreach (Proveedor::query()->whereIn('id', $ids('EQUIPO'))->with('persona')->get() as $p) {
            $nombres['EQUIPO:'.$p->id] = $p->persona->nombre_completo;
        }

        return $nombres;
    }
}
