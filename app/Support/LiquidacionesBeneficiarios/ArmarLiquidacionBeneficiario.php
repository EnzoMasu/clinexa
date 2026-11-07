<?php

namespace App\Support\LiquidacionesBeneficiarios;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\LineaLiquidacionBeneficiario;
use App\Models\LineaReparto;
use App\Models\LiquidacionBeneficiario;
use App\Models\Moneda;
use App\Models\Profesional;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Armar la liquidación de un beneficiario (docs/liquidacion-beneficiarios.md): el beneficiario (un profesional, o el dueño de un equipo)
 * y la fecha de corte (no futura), la vista previa y confirmar. Entra, en un solo paso:
 *
 * - ORDINARIAS: todas sus líneas del reparto percibidas y libres con fecha de servicio hasta el corte (lo pendiente anterior también);
 * - AJUSTES: en contra de cada ordinaria suya ya aprobada que dejó de corresponder (cobro revertido, factura anulada, visación aplicada,
 *   prestación anulada), sin ajuste todavía; se arman sin importar el corte;
 * - SALDO_ANTERIOR: el total negativo de cada liquidación suya aprobada que todavía no se trasladó.
 *
 * El total es la suma exacta de las líneas y puede quedar negativo (entonces pasa a la siguiente). Una sola abierta por beneficiario
 * (`liquidaciones_beneficiario_una_abierta`); los índices de las líneas frenan lo que se saltee la verificación. Queda en Auditoría: CREAR
 * de la liquidación y de cada línea ("Armado de la liquidación a beneficiario"). El permiso (CREAR sobre LIQUIDACIONES_BENEFICIARIOS) lo
 * controla la ruta.
 */
final class ArmarLiquidacionBeneficiario
{
    public const YA_ABIERTA = 'Este beneficiario ya tiene una liquidación abierta (en preparación o pendiente de aprobación): termínela o anúlela antes de armar otra.';

    public const SIN_LINEAS = 'No hay nada para liquidar a este beneficiario hasta esa fecha (ni prestaciones percibidas sin liquidar, ni ajustes).';

    public const CAMBIO = 'La lista cambió desde la vista previa: revísela y confirme de nuevo.';

    public const CORTE_FUTURO = 'La fecha de corte no puede ser posterior a hoy.';

    public const BENEFICIARIO_INVALIDO = 'Elija un profesional o un dueño de equipo.';

    /** Sus líneas del reparto que entran como ORDINARIA: percibidas, libres y hasta el corte. */
    public static function ordinarias(string $tipo, int $id, Carbon $corte): Builder
    {
        return Elegibilidad::libres(Elegibilidad::delBeneficiario(Elegibilidad::percibidas(), $tipo, $id))
            ->whereDate('p.fecha_servicio', '<=', $corte->format('Y-m-d'))
            ->with(['reparto.prestacion.paciente.persona', 'reparto.prestacion.procedimiento', 'equipo'])
            ->orderBy('p.fecha_servicio')->orderBy('p.id');
    }

    /** Sus ordinarias aprobadas, sin ajuste no liberado, cuya línea del reparto dejó de corresponder. */
    public static function aAjustar(string $tipo, int $id): Collection
    {
        $percibidas = Elegibilidad::percibidas()->select('lineas_reparto.id');

        return LineaLiquidacionBeneficiario::query()
            ->where('tipo', LineaLiquidacionBeneficiario::ORDINARIA)->whereNull('liberada_en')
            ->whereHas('liquidacion', fn (Builder $q) => $q->delBeneficiario($tipo, $id)->aprobadas())
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('lineas_liquidacion_beneficiario as aj')
                ->whereColumn('aj.ajusta_linea_id', 'lineas_liquidacion_beneficiario.id')
                ->where('aj.tipo', LineaLiquidacionBeneficiario::AJUSTE)->whereNull('aj.liberada_en'))
            ->whereNotIn('linea_reparto_id', $percibidas)
            ->with('lineaReparto.reparto.prestacion')
            ->orderBy('fecha_servicio')->orderBy('id')->get();
    }

    /** Sus liquidaciones aprobadas con total negativo cuyo saldo todavía no se trasladó. */
    public static function saldosEnContra(string $tipo, int $id): Collection
    {
        return LiquidacionBeneficiario::query()->delBeneficiario($tipo, $id)->aprobadas()->where('total', '<', 0)
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('lineas_liquidacion_beneficiario as sa')
                ->whereColumn('sa.saldo_de_liquidacion_id', 'liquidaciones_beneficiario.id')
                ->where('sa.tipo', LineaLiquidacionBeneficiario::SALDO_ANTERIOR)->whereNull('sa.liberada_en'))
            ->orderBy('id')->get();
    }

    /**
     * La vista previa: las líneas que se crearían (como arreglos con el snapshot), su cantidad y el total.
     *
     * @return array{lineas: list<array<string, mixed>>, cantidad: int, total: int}
     */
    public static function vistaPrevia(string $tipo, int $id, Carbon $corte): array
    {
        $lineas = [];
        foreach (self::ordinarias($tipo, $id, $corte)->get() as $linea) {
            $lineas[] = self::snapshotOrdinaria($tipo, $linea);
        }
        foreach (self::aAjustar($tipo, $id) as $ordinaria) {
            $lineas[] = self::snapshotAjuste($ordinaria);
        }
        foreach (self::saldosEnContra($tipo, $id) as $anterior) {
            $lineas[] = self::snapshotSaldo($anterior);
        }

        return ['lineas' => $lineas, 'cantidad' => count($lineas), 'total' => (int) array_sum(array_column($lineas, 'monto'))];
    }

    public static function verificarCorte(Carbon $corte): void
    {
        if ($corte->format('Y-m-d') > Fecha::hoy()->format('Y-m-d')) {
            throw new AccionRechazada(self::CORTE_FUTURO);
        }
    }

    /** Que el beneficiario exista: un profesional, o un proveedor dueño de algún equipo. */
    public static function verificarBeneficiario(string $tipo, int $id): void
    {
        $existe = match ($tipo) {
            LiquidacionBeneficiario::PROFESIONAL => Profesional::query()->whereKey($id)->exists(),
            LiquidacionBeneficiario::EQUIPO => Proveedor::query()->whereKey($id)->exists(),
            default => false,
        };
        if (! $existe) {
            throw new AccionRechazada(self::BENEFICIARIO_INVALIDO);
        }
    }

    public static function ejecutar(User $usuario, string $tipo, int $id, Carbon $corte, ?int $cantidadEsperada = null, ?int $totalEsperado = null): LiquidacionBeneficiario
    {
        self::verificarBeneficiario($tipo, $id);
        self::verificarCorte($corte);

        try {
            return DB::transaction(function () use ($usuario, $tipo, $id, $corte, $cantidadEsperada, $totalEsperado) {
                if (LiquidacionBeneficiario::query()->delBeneficiario($tipo, $id)->abiertas()->lockForUpdate()->exists()) {
                    throw new AccionRechazada(self::YA_ABIERTA);
                }
                $previa = self::vistaPrevia($tipo, $id, $corte);
                $conMovimiento = array_filter($previa['lineas'], fn (array $linea) => $linea['tipo'] !== LineaLiquidacionBeneficiario::SALDO_ANTERIOR);
                if ($conMovimiento === []) {
                    throw new AccionRechazada(self::SIN_LINEAS);
                }
                if (($cantidadEsperada !== null && $cantidadEsperada !== $previa['cantidad']) || ($totalEsperado !== null && $totalEsperado !== $previa['total'])) {
                    throw new AccionRechazada(self::CAMBIO);
                }

                return Auditoria::conDetalle("Armado de la liquidación a beneficiario ({$previa['cantidad']} líneas, corte al ".Fecha::mostrar($corte).')',
                    function () use ($usuario, $tipo, $id, $corte, $previa) {
                        $liquidacion = LiquidacionBeneficiario::create([
                            'beneficiario' => $tipo,
                            'profesional_id' => $tipo === LiquidacionBeneficiario::PROFESIONAL ? $id : null,
                            'proveedor_id' => $tipo === LiquidacionBeneficiario::EQUIPO ? $id : null,
                            'fecha_corte' => $corte->format('Y-m-d'),
                            'total' => $previa['total'],
                            'moneda_id' => Moneda::guarani()->id,
                            'armada_en' => now(),
                            'armada_por' => $usuario->id,
                        ]);
                        foreach ($previa['lineas'] as $linea) {
                            LineaLiquidacionBeneficiario::create(['liquidacion_beneficiario_id' => $liquidacion->id, ...$linea]);
                        }

                        return $liquidacion;
                    });
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'liquidaciones_beneficiario_una_abierta') || str_contains($e->getMessage(), 'liquidaciones_beneficiario.beneficiario')) {
                throw new AccionRechazada(self::YA_ABIERTA);
            }
            if (str_contains($e->getMessage(), 'lineas_liq_beneficiario_') || str_contains($e->getMessage(), 'lineas_liquidacion_beneficiario.')) {
                throw new AccionRechazada(self::CAMBIO);
            }
            throw $e;
        }
    }

    /** El snapshot de una ORDINARIA: sin nada clínico; la paciente, solo en la del profesional. */
    public static function snapshotOrdinaria(string $tipo, LineaReparto $linea): array
    {
        $prestacion = $linea->reparto->prestacion;
        $vez = LineaLiquidacionBeneficiario::query()->where('linea_reparto_id', $linea->id)
            ->where('tipo', LineaLiquidacionBeneficiario::ORDINARIA)->whereNull('liberada_en')->count() + 1;

        return [
            'tipo' => LineaLiquidacionBeneficiario::ORDINARIA,
            'linea_reparto_id' => $linea->id,
            'prestacion_id' => $prestacion->id,
            'vez' => $vez,
            'ajusta_linea_id' => null,
            'saldo_de_liquidacion_id' => null,
            'fecha_servicio' => $prestacion->fecha_servicio->format('Y-m-d'),
            'paciente' => $tipo === LiquidacionBeneficiario::PROFESIONAL ? mb_substr((string) $prestacion->paciente?->persona?->nombre_completo, 0, 200) : null,
            'procedimiento' => mb_substr($prestacion->procedimiento?->nombre ?? 'Sin procedimiento', 0, 150),
            'equipo' => $linea->equipo ? mb_substr($linea->equipo->nombre, 0, 150) : null,
            'monto_base' => (int) round((float) $linea->reparto->monto_base),
            'porcentaje' => $linea->porcentaje,
            'monto' => (int) round((float) $linea->monto),
            'motivo_ajuste' => null,
        ];
    }

    public static function snapshotAjuste(LineaLiquidacionBeneficiario $ordinaria): array
    {
        return [
            'tipo' => LineaLiquidacionBeneficiario::AJUSTE,
            'linea_reparto_id' => $ordinaria->linea_reparto_id,
            'prestacion_id' => $ordinaria->prestacion_id,
            'vez' => null,
            'ajusta_linea_id' => $ordinaria->id,
            'saldo_de_liquidacion_id' => null,
            'fecha_servicio' => $ordinaria->fecha_servicio?->format('Y-m-d'),
            'paciente' => $ordinaria->paciente,
            'procedimiento' => $ordinaria->procedimiento,
            'equipo' => $ordinaria->equipo,
            'monto_base' => $ordinaria->monto_base === null ? null : (int) round((float) $ordinaria->monto_base),
            'porcentaje' => $ordinaria->porcentaje,
            'monto' => -1 * $ordinaria->montoEntero(),
            'motivo_ajuste' => mb_substr((Elegibilidad::motivoDejoDeCorresponder($ordinaria->lineaReparto) ?? 'Dejó de corresponder.')
                ." (liquidada en la N.º {$ordinaria->liquidacion_beneficiario_id})", 0, 300),
        ];
    }

    public static function snapshotSaldo(LiquidacionBeneficiario $anterior): array
    {
        return [
            'tipo' => LineaLiquidacionBeneficiario::SALDO_ANTERIOR,
            'linea_reparto_id' => null, 'prestacion_id' => null, 'vez' => null, 'ajusta_linea_id' => null,
            'saldo_de_liquidacion_id' => $anterior->id,
            'fecha_servicio' => null, 'paciente' => null, 'procedimiento' => null, 'equipo' => null, 'monto_base' => null, 'porcentaje' => null,
            'monto' => $anterior->totalEntero(),
            'motivo_ajuste' => null,
        ];
    }
}
