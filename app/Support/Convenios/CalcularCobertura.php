<?php

namespace App\Support\Convenios;

use App\Models\CoberturaPaciente;
use App\Models\Estado;
use App\Models\LineaVisacion;
use App\Models\Prestacion;
use App\Models\TarifaConvenio;
use App\Models\Visacion;
use App\Support\Fecha;
use App\Support\Repartos\Porcentaje;
use App\Support\Tarifas\BuscarTarifa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quién paga una prestación y cuánto (docs/convenios-seguros.md), al fijar su monto: al crearla (GenerarPrestacion), al tarifarla
 * (TarifarPrestacion) o al "Aplicar visación". Nunca frena nada: sin visación, la prestación queda particular (con un aviso si la
 * paciente tenía cobertura).
 *
 * - Se busca una visación VIGENTE de la consulta (o del turno de la consulta) con una línea para el procedimiento.
 * - APROBADO: la tarifa del convenio (o la general como respaldo); la paciente paga según la línea (sin copago, porcentaje o monto
 *   fijo, nunca más que la tarifa) y la aseguradora el resto, "pendiente de liquidar". Redondeo al guaraní; el resto, a la aseguradora.
 * - NO_CUBIERTO: la tarifa GENERAL completa, la paga la paciente (la aseguradora no interviene).
 * - Sin visación para ese procedimiento, o con la cobertura o el convenio fuera de vigencia ese día: PARTICULAR (tarifa general).
 *
 * Solo para las prestaciones creadas desde el hito CONVENIOS: las anteriores siguen como estaban (sin snapshot del pagador).
 */
final class CalcularCobertura
{
    /** El hito que guarda la migración: desde cuándo las prestaciones buscan visación. */
    public const HITO = 'CONVENIOS';

    /**
     * Los atributos que fijan el monto de la prestación (monto, moneda, tarifa usada y snapshot del pagador), o null si no hay tarifa
     * (queda pendiente de tarifar). Con $visacion, se usa esa (Aplicar visación); con $particular, ninguna.
     *
     * @return array<string, mixed>|null
     */
    public static function atributos(Prestacion $prestacion, ?Visacion $visacion = null, bool $particular = false): ?array
    {
        $dia = $prestacion->fecha_servicio instanceof Carbon ? $prestacion->fecha_servicio->format('Y-m-d') : (string) $prestacion->fecha_servicio;
        $general = BuscarTarifa::para($prestacion->procedimiento_id, $prestacion->profesional_id, $dia);
        $deTarifa = fn ($tarifa) => ['monto' => (int) round((float) $tarifa->monto), 'moneda_id' => $tarifa->moneda_id];

        if (! self::corresponde($prestacion)) {
            return $general ? [...$deTarifa($general), 'tarifa_id' => $general->id] : null;
        }

        $linea = match (true) {
            $particular => null,
            $visacion !== null => $visacion->lineas()->where('procedimiento_id', $prestacion->procedimiento_id)->first(),
            default => self::lineaPara($prestacion),
        };
        $aviso = null;
        if ($linea !== null) {
            $deVisacion = $linea->visacion;
            $cobertura = $deVisacion->cobertura()->with('plan.convenio')->first();
            $convenio = $cobertura->plan->convenio;
            $aviso = match (true) {
                ! $cobertura->aplicaA($dia) => "La cobertura de la visación N.º {$deVisacion->numero} no estaba vigente el ".Fecha::mostrar($dia).': queda particular.',
                ! $convenio->aplicaA($dia) => "El convenio {$convenio->nombre} no estaba vigente el ".Fecha::mostrar($dia).': queda particular.',
                default => null,
            };
            $deLaVisacion = ['visacion_id' => $deVisacion->id, 'linea_visacion_id' => $linea->id, 'numero_visacion' => $deVisacion->numero];

            if ($aviso === null && ! $linea->aprobada()) {
                // No cubierto: la general completa, a cargo de la paciente. Queda la referencia a la visación.
                return $general ? [...$deTarifa($general), 'tarifa_id' => $general->id, 'tarifa_convenio_id' => null,
                    ...self::particular($deTarifa($general)['monto'], null), ...$deLaVisacion] : null;
            }
            if ($aviso === null) {
                $deConvenio = TarifaConvenio::query()->where('convenio_id', $convenio->id)->where('procedimiento_id', $prestacion->procedimiento_id)
                    ->aplicaA($dia)->orderByDesc('vigente_desde')->first();
                $tarifa = $deConvenio ?? $general;
                if ($tarifa === null) {
                    return null;
                }
                ['monto' => $total] = $deTarifa($tarifa);
                $paciente = self::partePaciente($linea, $total);

                return [...$deTarifa($tarifa), 'tarifa_id' => $deConvenio ? null : $tarifa->id, 'tarifa_convenio_id' => $deConvenio?->id,
                    'pagador' => Prestacion::ASEGURADORA, 'responsable_pago_id' => $convenio->responsable_pago_id, 'convenio_id' => $convenio->id,
                    'plan_convenio_id' => $cobertura->plan_convenio_id, ...$deLaVisacion,
                    'monto_paciente' => $paciente, 'monto_aseguradora' => $total - $paciente,
                    'liquidacion' => $total - $paciente > 0 ? Prestacion::PENDIENTE_DE_LIQUIDAR : null, 'aviso_cobertura' => null];
            }
        }

        if ($linea === null && ! $particular) {
            $aviso = self::avisoSinVisacion($prestacion, $dia);
        }

        return $general ? [...$deTarifa($general), 'tarifa_id' => $general->id, 'tarifa_convenio_id' => null,
            ...self::particular($deTarifa($general)['monto'], $aviso)] : null;
    }

    /** Lo que paga la paciente según la línea APROBADO: nada, un porcentaje (redondeado al guaraní) o un monto fijo; nunca más que el total. */
    public static function partePaciente(LineaVisacion $linea, int $total): int
    {
        return match ($linea->copago) {
            LineaVisacion::PORCENTAJE => min($total, Porcentaje::montoDe($total, Porcentaje::centesimas($linea->porcentaje))),
            LineaVisacion::MONTO_FIJO => min($total, (int) round((float) $linea->monto)),
            default => 0,
        };
    }

    /** Creada desde el hito CONVENIOS (o todavía sin crear). Las anteriores no buscan visación ni se recalculan. */
    public static function corresponde(Prestacion $prestacion): bool
    {
        $inicio = self::inicio();

        return $inicio !== null && ($prestacion->created_at === null || $prestacion->created_at->gte($inicio));
    }

    public static function inicio(): ?Carbon
    {
        $fecha = DB::table('hitos_sistema')->where('codigo', self::HITO)->value('fecha_hora');

        return $fecha ? Carbon::parse($fecha) : null;
    }

    /** La línea de una visación VIGENTE de la consulta (primero) o del turno de la prestación, para su procedimiento. */
    public static function lineaPara(Prestacion $prestacion): ?LineaVisacion
    {
        if ($prestacion->procedimiento_id === null) {
            return null;
        }

        return LineaVisacion::query()
            ->join('visaciones', 'visaciones.id', '=', 'lineas_visacion.visacion_id')
            ->join('coberturas_paciente', 'coberturas_paciente.id', '=', 'visaciones.cobertura_paciente_id')
            ->where('coberturas_paciente.paciente_id', $prestacion->paciente_id)
            ->where('visaciones.estado_id', Estado::idDe(Estado::VIGENTE))
            ->where('lineas_visacion.procedimiento_id', $prestacion->procedimiento_id)
            ->where(fn ($query) => $query->where('visaciones.consulta_id', $prestacion->consulta_id)
                ->when($prestacion->turno_id, fn ($query) => $query->orWhere('visaciones.turno_id', $prestacion->turno_id)))
            ->orderByRaw('visaciones.consulta_id IS NULL')->orderByDesc('visaciones.id')
            ->select('lineas_visacion.*')
            ->first();
    }

    /**
     * Por qué queda particular una paciente con cobertura (o null si no tenía): la visación de la consulta o del turno no incluye el
     * procedimiento, tiene cobertura sin visación para él, o su cobertura venció.
     */
    public static function avisoSinVisacion(Prestacion $prestacion, string $dia): ?string
    {
        $otra = Visacion::query()->vigentes()
            ->where(fn ($query) => $query->where('consulta_id', $prestacion->consulta_id)
                ->when($prestacion->turno_id, fn ($query) => $query->orWhere('turno_id', $prestacion->turno_id)))
            ->whereHas('cobertura', fn ($query) => $query->where('paciente_id', $prestacion->paciente_id))
            ->orderByDesc('id')->first();
        if ($otra !== null) {
            return "La visación N.º {$otra->numero} no incluye este procedimiento: queda particular.";
        }

        $cobertura = CoberturaPaciente::query()->where('paciente_id', $prestacion->paciente_id)->whereDate('vigente_desde', '<=', $dia)
            ->whereRaw('(vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)')
            ->orderByDesc('vigente_desde')->orderByDesc('id')->with('plan.convenio.responsablePago.persona')->first();

        return match (true) {
            $cobertura === null => null,
            $cobertura->aplicaA($dia) => "La paciente tiene cobertura ({$cobertura->plan->nombreCompleto()}) y no hay visación para este procedimiento: queda particular.",
            default => 'La cobertura de la paciente venció el '.Fecha::mostrar($cobertura->vigente_hasta).': queda particular.',
        };
    }

    /**
     * Las prestaciones con aviso de cobertura, para Recepción: las que quedaron particulares teniendo la paciente cobertura (sin
     * cobrar todavía) y las que tienen aplicada una visación que después se anuló. No anuladas.
     */
    public static function conAviso(): Builder
    {
        return Prestacion::query()
            ->where('prestaciones.estado_id', '!=', Estado::idDe(Estado::ANULADO))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $particular) => $particular->whereNotNull('prestaciones.aviso_cobertura')->whereDoesntHave('cobroVigente'))
                ->orWhereHas('visacion', fn (Builder $visacion) => $visacion->where('visaciones.estado_id', Estado::idDe(Estado::ANULADO))));
    }

    /** El snapshot de una particular: todo a cargo de la paciente. */
    private static function particular(int $total, ?string $aviso): array
    {
        return ['pagador' => Prestacion::PACIENTE, 'responsable_pago_id' => null, 'convenio_id' => null, 'plan_convenio_id' => null,
            'visacion_id' => null, 'linea_visacion_id' => null, 'numero_visacion' => null,
            'monto_paciente' => $total, 'monto_aseguradora' => 0, 'liquidacion' => null, 'aviso_cobertura' => $aviso];
    }
}
