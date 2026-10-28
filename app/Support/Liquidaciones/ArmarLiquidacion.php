<?php

namespace App\Support\Liquidaciones;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\LineaLiquidacion;
use App\Models\Liquidacion;
use App\Models\Moneda;
use App\Models\Prestacion;
use App\Models\ResponsablePago;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Armar la liquidación de una aseguradora (docs/liquidaciones-aseguradoras.md), en un solo paso: la aseguradora y la fecha de corte (no
 * futura), la vista previa y confirmar. Entran TODAS sus prestaciones con pagador ASEGURADORA, pendientes de liquidar (lo de la
 * aseguradora mayor que cero), firma recibida, no anuladas, sin liquidar y con fecha de servicio hasta el corte. Las que no tienen la
 * firma no entran: quedan para la siguiente (`sinFirma`, para gestionarlas).
 *
 * Una línea por prestación con el snapshot de la planilla; el total es la suma exacta de las líneas. Una sola abierta por aseguradora
 * (`liquidaciones_una_abierta`) y una prestación en una sola (`lineas_liquidacion_una_por_prestacion`): los índices frenan lo que se
 * saltee la verificación. Queda en Auditoría: CREAR de la liquidación y de cada línea ("Armado de la liquidación"). El permiso (CREAR
 * sobre LIQUIDACIONES: Facturación y convenios y el Administrador) lo controla la ruta.
 */
final class ArmarLiquidacion
{
    public const YA_ABIERTA = 'Esta aseguradora ya tiene una liquidación abierta (en preparación o pendiente de aprobación): termínela o anúlela antes de armar otra.';

    public const SIN_PRESTACIONES = 'No hay prestaciones para liquidar de esa aseguradora hasta esa fecha (con la firma recibida y sin liquidar).';

    public const CAMBIO = 'La lista de prestaciones cambió desde la vista previa: revísela y confirme de nuevo.';

    public const CORTE_FUTURO = 'La fecha de corte no puede ser posterior a hoy.';

    /** Las prestaciones de aseguradora pendientes de liquidar y libres (sin una línea no liberada), de esa aseguradora y hasta el corte. */
    public static function libres(?int $responsableId = null, ?Carbon $corte = null): Builder
    {
        return Prestacion::query()
            ->where('prestaciones.pagador', Prestacion::ASEGURADORA)
            ->where('prestaciones.liquidacion', Prestacion::PENDIENTE_DE_LIQUIDAR)
            ->where('prestaciones.estado_id', '!=', Estado::idDe(Estado::ANULADO))
            ->when($responsableId, fn ($query) => $query->where('prestaciones.responsable_pago_id', $responsableId))
            ->when($corte, fn ($query) => $query->whereDate('prestaciones.fecha_servicio', '<=', $corte->format('Y-m-d')))
            ->whereDoesntHave('lineaLiquidacion');
    }

    /** Las que entran: libres y con la firma recibida. */
    public static function candidatas(int $responsableId, Carbon $corte): Builder
    {
        return self::libres($responsableId, $corte)->whereNotNull('prestaciones.firma_recibida_en');
    }

    /** Las que quedan afuera por falta de firma (de esa aseguradora y corte, o todas). */
    public static function sinFirma(?int $responsableId = null, ?Carbon $corte = null): Builder
    {
        return self::libres($responsableId, $corte)->whereNull('prestaciones.firma_recibida_en');
    }

    /** La vista previa: las candidatas con lo necesario para el snapshot, en el orden de la planilla. */
    public static function vistaPrevia(int $responsableId, Carbon $corte): Collection
    {
        return self::conDatos(self::candidatas($responsableId, $corte))->get();
    }

    /** Las fechas de corte futuras se rechazan. */
    public static function verificarCorte(Carbon $corte): void
    {
        if ($corte->format('Y-m-d') > Fecha::hoy()->format('Y-m-d')) {
            throw new AccionRechazada(self::CORTE_FUTURO);
        }
    }

    /**
     * @param  ?int  $cantidadEsperada  y $totalEsperado: lo que mostró la vista previa; si la lista cambió, se rechaza.
     */
    public static function ejecutar(User $usuario, ResponsablePago $responsable, Carbon $corte, ?int $cantidadEsperada = null, ?int $totalEsperado = null): Liquidacion
    {
        self::verificarCorte($corte);

        try {
            return DB::transaction(function () use ($usuario, $responsable, $corte, $cantidadEsperada, $totalEsperado) {
                if (Liquidacion::query()->abiertas()->where('responsable_pago_id', $responsable->id)->lockForUpdate()->exists()) {
                    throw new AccionRechazada(self::YA_ABIERTA);
                }
                // Bloqueadas mientras se arma: nadie aplica una visación ni desmarca la firma a la vez.
                $ids = self::candidatas($responsable->id, $corte)->lockForUpdate()->pluck('prestaciones.id');
                $prestaciones = self::conDatos(Prestacion::query()->whereIn('prestaciones.id', $ids))->get();
                if ($prestaciones->isEmpty()) {
                    throw new AccionRechazada(self::SIN_PRESTACIONES);
                }
                $total = $prestaciones->sum(fn (Prestacion $prestacion) => self::montoAseguradora($prestacion));
                if (($cantidadEsperada !== null && $cantidadEsperada !== $prestaciones->count()) || ($totalEsperado !== null && $totalEsperado !== $total)) {
                    throw new AccionRechazada(self::CAMBIO);
                }
                $convenios = $prestaciones->pluck('convenio_id')->unique();

                return Auditoria::conDetalle("Armado de la liquidación ({$prestaciones->count()} prestaciones, corte al ".Fecha::mostrar($corte).')',
                    function () use ($usuario, $responsable, $corte, $prestaciones, $total, $convenios) {
                        $liquidacion = Liquidacion::create([
                            'responsable_pago_id' => $responsable->id,
                            'convenio_id' => $convenios->count() === 1 ? $convenios->first() : null,
                            'fecha_corte' => $corte->format('Y-m-d'),
                            'total' => $total,
                            'moneda_id' => Moneda::guarani()->id,
                            'armada_en' => now(),
                            'armada_por' => $usuario->id,
                        ]);
                        foreach ($prestaciones as $prestacion) {
                            LineaLiquidacion::create(['liquidacion_id' => $liquidacion->id, ...self::snapshot($prestacion)]);
                        }

                        return $liquidacion;
                    });
            });
        } catch (QueryException $e) {
            // Otra liquidación ganó por los índices únicos parciales.
            if (str_contains($e->getMessage(), 'liquidaciones_una_abierta') || str_contains($e->getMessage(), 'liquidaciones.responsable_pago_id')) {
                throw new AccionRechazada(self::YA_ABIERTA);
            }
            if (str_contains($e->getMessage(), 'lineas_liquidacion_una_por_prestacion') || str_contains($e->getMessage(), 'lineas_liquidacion.prestacion_id')) {
                throw new AccionRechazada(self::CAMBIO);
            }
            throw $e;
        }
    }

    /** El snapshot de la línea: sin nada clínico. */
    public static function snapshot(Prestacion $prestacion): array
    {
        $persona = $prestacion->paciente->persona;

        return [
            'prestacion_id' => $prestacion->id,
            'paciente' => mb_substr($persona->nombre_completo, 0, 200),
            'documento' => mb_substr(trim(($persona->tipoDocumento?->codigo ?? '').' '.$persona->nro_documento), 0, 40),
            'numero_asegurado' => (string) $prestacion->visacion->cobertura->numero_asegurado,
            'fecha_servicio' => $prestacion->fecha_servicio->format('Y-m-d'),
            'procedimiento' => mb_substr($prestacion->procedimiento?->nombre ?? 'Sin procedimiento', 0, 150),
            'numero_visacion' => (string) $prestacion->numero_visacion,
            'monto' => self::montoAseguradora($prestacion),
        ];
    }

    public static function montoAseguradora(Prestacion $prestacion): int
    {
        return (int) round((float) $prestacion->monto_aseguradora);
    }

    private static function conDatos(Builder $query): Builder
    {
        return $query->with(['paciente.persona.tipoDocumento', 'procedimiento', 'visacion.cobertura', 'moneda'])
            ->orderBy('prestaciones.fecha_servicio')->orderBy('prestaciones.id');
    }
}
