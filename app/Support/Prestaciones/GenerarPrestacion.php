<?php

namespace App\Support\Prestaciones;

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Turno;
use App\Support\Auditoria;
use App\Support\Fecha;
use App\Support\Tarifas\BuscarTarifa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La prestación de una consulta (docs/tarifas-prestaciones.md). Se crea al FINALIZARLA (alFinalizar, lo llama
 * Atencion\Finalizar en su transacción) o, si eso falló, con "Generar" desde Prestaciones (generar).
 *
 * - Procedimiento: el del turno; sin turno o sin procedimiento, el procedimiento Consulta (por su código,
 *   config('clinexa.prestaciones.procedimiento_consulta')). Si tampoco existe, queda sin procedimiento.
 * - Fecha de servicio: el día de la atención (iniciada_en, en la hora local).
 * - Con tarifa para ese día (la del profesional o la general): nace TARIFADO, con el monto fijado. Sin tarifa: PENDIENTE
 *   ("pendiente de tarifar"), sin monto.
 * - Una por consulta: si ya existe, no hace nada (y la base tiene consulta_id único). La adenda y el desbloqueo no pasan por acá.
 *
 * Al finalizar nunca impide cerrar la consulta: corre en un punto de guardado propio y, si falla, se deshace solo lo suyo, se
 * reporta en el log y queda un evento FALLO en Auditoría (consulta, profesional y fecha, sin contenido clínico). La consulta
 * aparece después en "Consultas sin prestación" (sinPrestacion), solo las finalizadas desde que existe el módulo.
 */
final class GenerarPrestacion
{
    /** El hito que guarda la migración: desde cuándo se generan prestaciones. */
    public const HITO = 'PRESTACIONES';

    public static function alFinalizar(Consulta $consulta, ?Turno $turno): ?Prestacion
    {
        try {
            return DB::transaction(fn () => self::crear($consulta, $turno, "Al finalizar la consulta {$consulta->id}"));
        } catch (Throwable $e) {
            report($e);
            Auditoria::registrar(AccionAuditoria::FALLO, 'prestaciones', null, nuevo: [
                'consulta_id' => $consulta->id,
                'profesional_id' => $consulta->profesional_id,
                'fecha_servicio' => Fecha::diaLocal($consulta->fechaAtencion()),
            ], detalle: "No se pudo crear la prestación de la consulta {$consulta->id} al finalizarla. Generarla desde Prestaciones (Consultas sin prestación).");

            return null;
        }
    }

    /** "Generar" desde Prestaciones: solo una consulta FINALIZADA desde el inicio del módulo y sin prestación. */
    public static function generar(Consulta $consulta): Prestacion
    {
        return DB::transaction(function () use ($consulta) {
            $actual = self::sinPrestacion()->whereKey($consulta->getKey())->lockForUpdate()->first()
                ?? throw new AccionRechazada('Esta consulta ya tiene su prestación o no corresponde generarla.');

            return self::crear($actual, $actual->turno_id ? Turno::find($actual->turno_id) : null, "Generada desde Prestaciones (consulta {$actual->id})")
                ?? throw new AccionRechazada('Esta consulta ya tiene su prestación.');
        });
    }

    /** Desde cuándo se generan prestaciones (null si la migración todavía no corrió). */
    public static function inicio(): ?Carbon
    {
        $fecha = DB::table('hitos_sistema')->where('codigo', self::HITO)->value('fecha_hora');

        return $fecha ? Carbon::parse($fecha) : null;
    }

    /** Consultas FINALIZADAS desde el inicio del módulo que no tienen prestación. Las anteriores nunca entran. */
    public static function sinPrestacion(): Builder
    {
        $inicio = self::inicio();

        return Consulta::query()->finalizadas()
            ->when($inicio, fn ($query) => $query->where('finalizada_en', '>=', $inicio), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('prestaciones')->whereColumn('prestaciones.consulta_id', 'consultas.id'));
    }

    private static function crear(Consulta $consulta, ?Turno $turno, string $detalle): ?Prestacion
    {
        if (Prestacion::where('consulta_id', $consulta->id)->exists()) {
            return null;
        }

        $procedimientoId = $turno?->procedimiento_id
            ?? Procedimiento::where('codigo', config('clinexa.prestaciones.procedimiento_consulta'))->value('id');
        $dia = Fecha::diaLocal($consulta->fechaAtencion());
        $tarifa = BuscarTarifa::para($procedimientoId, $consulta->profesional_id, $dia);

        return Auditoria::conDetalle($detalle, function () use ($consulta, $turno, $procedimientoId, $dia, $tarifa) {
            $prestacion = new Prestacion([
                'consulta_id' => $consulta->id,
                'turno_id' => $turno?->id,
                'paciente_id' => $consulta->historiaClinica()->value('paciente_id'),
                'profesional_id' => $consulta->profesional_id,
                'procedimiento_id' => $procedimientoId,
                'fecha_servicio' => $dia,
            ]);
            $prestacion->forceFill($tarifa
                ? ['estado_id' => Estado::idDe(Estado::TARIFADO), 'monto' => $tarifa->monto, 'moneda_id' => $tarifa->moneda_id,
                    'tarifa_id' => $tarifa->id, 'tarifada_en' => now()]
                : ['estado_id' => Estado::idDe(Estado::PENDIENTE)])->save();

            return $prestacion;
        });
    }
}
