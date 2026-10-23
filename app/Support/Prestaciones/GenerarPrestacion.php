<?php

namespace App\Support\Prestaciones;

use App\Enums\AccionAuditoria;
use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\InformeEstudio;
use App\Models\Prestacion;
use App\Models\Procedimiento;
use App\Models\Turno;
use App\Support\Auditoria;
use App\Support\Convenios\CalcularCobertura;
use App\Support\Fecha;
use App\Support\Repartos\RepartirPrestacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Las prestaciones de una consulta (docs/tarifas-prestaciones.md). Se crean al FINALIZARLA (alFinalizar, lo llama
 * Atencion\Finalizar en su transacción) o, si eso falló, con "Generar" desde Prestaciones (generar, generarInforme).
 *
 * - La BASE, una por consulta: el procedimiento del turno; sin turno o sin procedimiento, el procedimiento Consulta (por su
 *   código, config('clinexa.prestaciones.procedimiento_consulta')). Si tampoco existe, queda sin procedimiento.
 * - Una por cada INFORME DE ESTUDIO vigente con procedimiento (informe_estudio_id), escrito antes de finalizar. Si el turno era
 *   de ese mismo procedimiento, la base cubre UN informe (el primero en la pantalla); los demás generan la suya.
 * - Fecha de servicio: el día de la atención (iniciada_en, en la hora local). Profesional: el de la consulta.
 * - Con tarifa para ese día (la del profesional o la general; con una visación aprobada, la del convenio): nace TARIFADO, con el
 *   monto y quién paga fijados (Convenios\CalcularCobertura, docs/convenios-seguros.md). Sin tarifa: PENDIENTE ("pendiente de
 *   tarifar"), sin monto.
 * - Sin duplicar: si ya existe, no hace nada (y la base tiene índices únicos parciales). La adenda y el desbloqueo no pasan por
 *   acá; retirar un informe después no anula su prestación (la anula Gerencia con motivo).
 * - Con monto, calcula su reparto (Repartos\RepartirPrestacion::alFijarMonto, docs/reparto-economico.md), que tampoco frena nada.
 *
 * Al finalizar nunca impide cerrar la consulta: cada prestación corre en un punto de guardado propio y, si falla, se deshace
 * solo lo suyo, se reporta en el log y queda un evento FALLO en Auditoría (consulta, informe, profesional y fecha, sin contenido
 * clínico). Lo que falta aparece después en "Consultas sin prestación" (sinPrestacion, informesSinPrestacion), solo lo
 * finalizado desde que existe cada hito.
 */
final class GenerarPrestacion
{
    /** El hito que guarda la migración: desde cuándo se generan prestaciones. */
    public const HITO = 'PRESTACIONES';

    /** Desde cuándo los informes de estudio generan la suya. */
    public const HITO_INFORMES = 'PRESTACIONES_INFORMES';

    public static function alFinalizar(Consulta $consulta, ?Turno $turno): ?Prestacion
    {
        try {
            $base = DB::transaction(fn () => self::crear($consulta, $turno, null, "Al finalizar la consulta {$consulta->id}"));
        } catch (Throwable $e) {
            self::fallo($consulta, null, $e, "No se pudo crear la prestación de la consulta {$consulta->id} al finalizarla.");
            $base = null;
        }

        try {
            $informes = self::informesSinPrestacion()->where('informes_estudio.consulta_id', $consulta->id)->get();
        } catch (Throwable $e) {
            self::fallo($consulta, null, $e, "No se pudieron buscar los informes de estudio de la consulta {$consulta->id} al finalizarla.");
            $informes = collect();
        }
        foreach ($informes as $informe) {
            try {
                DB::transaction(fn () => self::crear($consulta, $turno, $informe, "Al finalizar la consulta {$consulta->id} (informe de estudio {$informe->id})"));
            } catch (Throwable $e) {
                self::fallo($consulta, $informe, $e, "No se pudo crear la prestación del informe de estudio {$informe->id} de la consulta {$consulta->id} al finalizarla.");
            }
        }

        return $base;
    }

    /** "Generar" desde Prestaciones: solo una consulta FINALIZADA desde el inicio del módulo y sin prestación base. */
    public static function generar(Consulta $consulta): Prestacion
    {
        return DB::transaction(function () use ($consulta) {
            $actual = self::sinPrestacion()->whereKey($consulta->getKey())->lockForUpdate()->first()
                ?? throw new AccionRechazada('Esta consulta ya tiene su prestación o no corresponde generarla.');

            return self::crear($actual, $actual->turno_id ? Turno::find($actual->turno_id) : null, null, "Generada desde Prestaciones (consulta {$actual->id})")
                ?? throw new AccionRechazada('Esta consulta ya tiene su prestación.');
        });
    }

    /** "Generar" la de un informe de estudio sin prestación (informesSinPrestacion), con el mismo servicio que al finalizar. */
    public static function generarInforme(InformeEstudio $informe): Prestacion
    {
        return DB::transaction(function () use ($informe) {
            // La consulta bloqueada: dos "Generar" a la vez esperan uno al otro (y el segundo ya no lo encuentra pendiente).
            $consulta = Consulta::query()->whereKey($informe->consulta_id)->lockForUpdate()->first();
            $actual = self::informesSinPrestacion()->where('informes_estudio.id', $informe->getKey())->first();
            if ($consulta === null || $actual === null) {
                throw new AccionRechazada('Este informe de estudio ya tiene su prestación o no corresponde generarla.');
            }

            return self::crear($consulta, $consulta->turno_id ? Turno::find($consulta->turno_id) : null, $actual,
                "Generada desde Prestaciones (consulta {$consulta->id}, informe de estudio {$actual->id})")
                ?? throw new AccionRechazada('Este informe de estudio ya tiene su prestación.');
        });
    }

    /** Desde cuándo se generan prestaciones, o las de los informes (null si la migración todavía no corrió). */
    public static function inicio(string $hito = self::HITO): ?Carbon
    {
        $fecha = DB::table('hitos_sistema')->where('codigo', $hito)->value('fecha_hora');

        return $fecha ? Carbon::parse($fecha) : null;
    }

    /** Consultas FINALIZADAS desde el inicio del módulo que no tienen prestación base. Las anteriores nunca entran. */
    public static function sinPrestacion(): Builder
    {
        $inicio = self::inicio();

        return Consulta::query()->finalizadas()
            ->when($inicio, fn ($query) => $query->where('finalizada_en', '>=', $inicio), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('prestaciones')
                ->whereColumn('prestaciones.consulta_id', 'consultas.id')->whereNull('prestaciones.informe_estudio_id'));
    }

    /**
     * Informes de estudio que deben tener su prestación y no la tienen: vigentes, con procedimiento, escritos antes de finalizar
     * (lo agregado por una adenda o un desbloqueo no cuenta) en una consulta FINALIZADA desde el hito de los informes, y que no
     * cubre la base (el primero, en el orden de la pantalla, del mismo procedimiento que el turno). En el orden de la pantalla.
     */
    public static function informesSinPrestacion(): Builder
    {
        $inicio = self::inicio(self::HITO_INFORMES);

        return InformeEstudio::query()
            ->select('informes_estudio.*')
            ->join('consultas', 'consultas.id', '=', 'informes_estudio.consulta_id')
            ->where('consultas.estado_id', Estado::idDe(Estado::FINALIZADO))
            ->when($inicio, fn ($query) => $query->where('consultas.finalizada_en', '>=', $inicio), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('informes_estudio.activo', true)
            ->whereNotNull('informes_estudio.procedimiento_id')
            ->whereColumn('informes_estudio.created_at', '<=', 'consultas.finalizada_en')
            ->whereNotExists(fn (QueryBuilder $query) => $query->select(DB::raw(1))->from('prestaciones')
                ->whereColumn('prestaciones.informe_estudio_id', 'informes_estudio.id'))
            // Cubierto por la base: el turno es de ese procedimiento y no hay antes otro informe vigente del mismo.
            ->whereNot(fn (Builder $query) => $query
                ->whereExists(fn (QueryBuilder $turno) => $turno->select(DB::raw(1))->from('turnos')
                    ->whereColumn('turnos.id', 'consultas.turno_id')
                    ->whereColumn('turnos.procedimiento_id', 'informes_estudio.procedimiento_id'))
                ->whereNotExists(fn (QueryBuilder $anterior) => $anterior->select(DB::raw(1))->from('informes_estudio as anterior')
                    ->whereColumn('anterior.consulta_id', 'informes_estudio.consulta_id')
                    ->whereColumn('anterior.procedimiento_id', 'informes_estudio.procedimiento_id')
                    ->where('anterior.activo', true)
                    ->whereColumn('anterior.created_at', '<=', 'consultas.finalizada_en')
                    ->where(fn (QueryBuilder $orden) => $orden->whereColumn('anterior.orden', '<', 'informes_estudio.orden')
                        ->orWhere(fn (QueryBuilder $empate) => $empate->whereColumn('anterior.orden', 'informes_estudio.orden')
                            ->whereColumn('anterior.id', '<', 'informes_estudio.id')))))
            ->orderBy('consultas.finalizada_en')->orderBy('informes_estudio.orden')->orderBy('informes_estudio.id');
    }

    /** El procedimiento de la prestación base: el del turno o, si no hay, el procedimiento Consulta (por su código). */
    private static function procedimientoBase(?Turno $turno): ?int
    {
        return $turno?->procedimiento_id
            ?? Procedimiento::where('codigo', config('clinexa.prestaciones.procedimiento_consulta'))->value('id');
    }

    /** La base ($informe null) o la de un informe. Si ya existe, null. */
    private static function crear(Consulta $consulta, ?Turno $turno, ?InformeEstudio $informe, string $detalle): ?Prestacion
    {
        $existe = Prestacion::where('consulta_id', $consulta->id)
            ->when($informe, fn ($query) => $query->where('informe_estudio_id', $informe->id), fn ($query) => $query->whereNull('informe_estudio_id'))
            ->exists();
        if ($existe) {
            return null;
        }

        $procedimientoId = $informe ? $informe->procedimiento_id : self::procedimientoBase($turno);
        $dia = Fecha::diaLocal($consulta->fechaAtencion());

        return Auditoria::conDetalle($detalle, function () use ($consulta, $turno, $informe, $procedimientoId, $dia) {
            $prestacion = new Prestacion([
                'consulta_id' => $consulta->id,
                'turno_id' => $turno?->id,
                'informe_estudio_id' => $informe?->id,
                'paciente_id' => $consulta->historiaClinica()->value('paciente_id'),
                'profesional_id' => $consulta->profesional_id,
                'procedimiento_id' => $procedimientoId,
                'fecha_servicio' => $dia,
            ]);
            // La tarifa y quién paga (docs/convenios-seguros.md): con visación, la del convenio y la parte de cada uno; sin visación,
            // particular (con un aviso si la paciente tenía cobertura). Nunca frena la finalización.
            $atributos = CalcularCobertura::atributos($prestacion);
            $prestacion->forceFill($atributos
                ? ['estado_id' => Estado::idDe(Estado::TARIFADO), ...$atributos, 'tarifada_en' => now()]
                : ['estado_id' => Estado::idDe(Estado::PENDIENTE)])->save();
            // Con monto, su reparto (docs/reparto-economico.md): nunca impide crearla; sin acuerdos queda pendiente de reparto.
            RepartirPrestacion::alFijarMonto($prestacion);

            return $prestacion;
        });
    }

    /** Lo que falló al finalizar: al log y un FALLO en Auditoría (sin contenido clínico). */
    private static function fallo(Consulta $consulta, ?InformeEstudio $informe, Throwable $e, string $detalle): void
    {
        report($e);
        Auditoria::registrar(AccionAuditoria::FALLO, 'prestaciones', null, nuevo: [
            'consulta_id' => $consulta->id,
            ...($informe ? ['informe_estudio_id' => $informe->id] : []),
            'profesional_id' => $consulta->profesional_id,
            'fecha_servicio' => Fecha::diaLocal($consulta->fechaAtencion()),
        ], detalle: "{$detalle} Generarla desde Prestaciones (Consultas sin prestación).");
    }
}
