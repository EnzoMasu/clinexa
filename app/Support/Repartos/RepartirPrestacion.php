<?php

namespace App\Support\Repartos;

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\LineaReparto;
use App\Models\Prestacion;
use App\Models\RepartoPrestacion;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * El reparto de una prestación (docs/reparto-economico.md), fijado una vez:
 *
 * - alFijarMonto: al crearse la prestación con monto (GenerarPrestacion) o al tarifarla (TarifarPrestacion). Nunca frena la
 *   finalización ni el tarifado: corre en un punto de guardado propio y, si falla, se deshace solo lo suyo, se reporta en el
 *   log y queda un evento FALLO en Auditoría (prestación, profesional y fecha de servicio, sin contenido clínico).
 * - repartir: el botón "Repartir" de una pendiente (quien administra los acuerdos), con los acuerdos de su fecha de servicio.
 *   Dos veces no duplica: un reparto por prestación, y uno REPARTIDO ya no se recalcula.
 * - anular: al anular la prestación, su reparto queda ANULADO con el motivo (nada se borra).
 * - reemplazar: al aplicar una visación que cambia el total (docs/convenios-seguros.md), el reparto anterior queda ANULADO (con sus
 *   líneas) y se calcula otro sobre el total nuevo. Uno por prestación entre los no anulados.
 *
 * Sin acuerdo del profesional, sin el del equipo (si el procedimiento usa uno) o con porcentajes que superan 100, queda
 * PENDIENTE con el motivo. Solo las prestaciones creadas desde que existe el módulo (hito REPARTOS) se reparten.
 */
final class RepartirPrestacion
{
    /** El hito que guarda la migración: desde cuándo se reparten prestaciones. */
    public const HITO = 'REPARTOS';

    public const YA_REPARTIDA = 'Esta prestación ya está repartida.';

    public const NO_CORRESPONDE = 'Esta prestación no se reparte: no está tarifada, está anulada o es anterior a los repartos.';

    public static function alFijarMonto(Prestacion $prestacion): ?RepartoPrestacion
    {
        try {
            if (! self::corresponde($prestacion)) {
                return null;
            }

            return DB::transaction(fn () => Auditoria::conDetalle("Reparto de la prestación {$prestacion->id}", fn () => self::calcular($prestacion, null)));
        } catch (Throwable $e) {
            report($e);
            Auditoria::registrar(AccionAuditoria::FALLO, 'repartos_prestacion', null, nuevo: [
                'prestacion_id' => $prestacion->id,
                'profesional_id' => $prestacion->profesional_id,
                'fecha_servicio' => $prestacion->fecha_servicio->format('Y-m-d'),
            ], detalle: "No se pudo calcular el reparto de la prestación {$prestacion->id}. Repártala desde Prestaciones (pendientes de reparto).");

            return null;
        }
    }

    /** "Repartir" una pendiente desde Prestaciones, con los acuerdos vigentes en su fecha de servicio. */
    public static function repartir(User $usuario, Prestacion $prestacion): RepartoPrestacion
    {
        return DB::transaction(function () use ($usuario, $prestacion) {
            $actual = Prestacion::query()->whereKey($prestacion->getKey())->lockForUpdate()->firstOrFail();
            if (! self::corresponde($actual)) {
                throw new AccionRechazada(self::NO_CORRESPONDE);
            }

            return Auditoria::conDetalle("Repartir (prestación {$actual->id})", fn () => self::calcular($actual, $usuario));
        });
    }

    /** Al anular la prestación (en su transacción): su reparto, si tiene, queda ANULADO con el motivo. */
    public static function anular(User $usuario, Prestacion $prestacion, string $motivo): void
    {
        $reparto = RepartoPrestacion::query()->where('prestacion_id', $prestacion->id)->whereNull('anulado_en')->lockForUpdate()->first();
        if ($reparto === null || $reparto->anulado()) {
            return;
        }

        $reparto->forceFill([
            'estado_id' => Estado::idDe(Estado::ANULADO),
            'anulado_en' => now(),
            'motivo_anulacion' => "Prestación anulada: {$motivo}",
            'anulado_por' => $usuario->id,
        ])->save();
    }

    /**
     * Al aplicar una visación que cambió el total (en su transacción): anula el reparto vigente, si hay, y calcula otro con los
     * acuerdos de la fecha de servicio. Sin acuerdos, el nuevo queda pendiente. Nunca frena la aplicación (como alFijarMonto).
     */
    public static function reemplazar(User $usuario, Prestacion $prestacion, string $motivo): ?RepartoPrestacion
    {
        $reparto = RepartoPrestacion::query()->where('prestacion_id', $prestacion->id)->whereNull('anulado_en')->lockForUpdate()->first();
        if ($reparto !== null) {
            $reparto->forceFill([
                'estado_id' => Estado::idDe(Estado::ANULADO),
                'anulado_en' => now(),
                'motivo_anulacion' => $motivo,
                'anulado_por' => $usuario->id,
            ])->save();
        }

        return self::alFijarMonto($prestacion);
    }

    /** Desde cuándo se reparten prestaciones (null si la migración todavía no corrió). */
    public static function inicio(): ?Carbon
    {
        $fecha = DB::table('hitos_sistema')->where('codigo', self::HITO)->value('fecha_hora');

        return $fecha ? Carbon::parse($fecha) : null;
    }

    /** Tarifada (con monto), no anulada y creada desde el inicio de los repartos. */
    public static function corresponde(Prestacion $prestacion): bool
    {
        $inicio = self::inicio();

        return $inicio !== null && $prestacion->monto !== null && $prestacion->tieneEstado(Estado::TARIFADO)
            && $prestacion->created_at !== null && $prestacion->created_at->gte($inicio);
    }

    /**
     * Las prestaciones pendientes de reparto: tarifadas desde el inicio de los repartos, con su reparto PENDIENTE o sin reparto
     * (si el cálculo falló). Las anteriores nunca entran.
     */
    public static function pendientes(): Builder
    {
        $inicio = self::inicio();

        return Prestacion::query()
            ->where('prestaciones.estado_id', Estado::idDe(Estado::TARIFADO))
            ->when($inicio, fn ($query) => $query->where('prestaciones.created_at', '>=', $inicio), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('repartos_prestacion')
                ->whereColumn('repartos_prestacion.prestacion_id', 'prestaciones.id')
                ->whereNull('repartos_prestacion.anulado_en')
                ->where('repartos_prestacion.estado_id', '!=', Estado::idDe(Estado::PENDIENTE)));
    }

    private static function calcular(Prestacion $prestacion, ?User $usuario): RepartoPrestacion
    {
        $reparto = RepartoPrestacion::query()->where('prestacion_id', $prestacion->id)->whereNull('anulado_en')->lockForUpdate()->first();
        if ($reparto && ! $reparto->pendiente()) {
            throw new AccionRechazada($reparto->repartido() ? self::YA_REPARTIDA : self::NO_CORRESPONDE);
        }

        $calculo = CalcularReparto::para($prestacion);
        $reparto ??= new RepartoPrestacion(['prestacion_id' => $prestacion->id]);

        if ($calculo['motivo'] !== null) {
            $reparto->forceFill(['motivo_pendiente' => $calculo['motivo']]);
            if (! $reparto->exists) {
                $reparto->forceFill(['estado_id' => Estado::idDe(Estado::PENDIENTE), 'monto_base' => $prestacion->monto, 'moneda_id' => $prestacion->moneda_id]);
            }
            $reparto->save();

            return $reparto;
        }

        $reparto->forceFill([
            'estado_id' => Estado::idDe(Estado::REPARTIDO),
            'motivo_pendiente' => null,
            'monto_base' => $prestacion->monto,
            'moneda_id' => $prestacion->moneda_id,
            'equipo_id' => $calculo['equipo']?->id,
            'repartido_en' => now(),
            'repartido_por' => $usuario?->id,
        ])->save();
        foreach ($calculo['lineas'] as $linea) {
            LineaReparto::create(['reparto_prestacion_id' => $reparto->id, ...$linea]);
        }
        $reparto->unsetRelation('estado');

        return $reparto;
    }
}
