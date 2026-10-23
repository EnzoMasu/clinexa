<?php

namespace App\Support\Convenios;

use App\Exceptions\AccionRechazada;
use App\Models\Convenio;
use App\Models\Estado;
use App\Models\Moneda;
use App\Models\PlanConvenio;
use App\Models\ResponsablePago;
use App\Models\TarifaConvenio;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Convenios, planes y tarifas del convenio (docs/convenios-seguros.md). Nada se borra: el convenio pasa a histórico, el plan se
 * desactiva y la tarifa se cierra.
 *
 * - alta: el convenio con un responsable de pago ACTIVO y sus planes (uno por línea), en un solo paso.
 * - guardarTarifa: un solo formulario para el alta y el cambio. Sin tarifa abierta para ese convenio y procedimiento, la crea;
 *   con una, la cierra (HISTORICO, "hasta" = el día anterior) y abre otra desde hoy o después (patrón de las tarifas generales).
 * - bajaTarifa: el procedimiento deja de figurar en el convenio (último día; desde el siguiente se usa la tarifa general).
 *
 * Todo queda en Auditoría (CREAR, EDITAR o DESACTIVAR, con el detalle). El permiso lo controla la ruta (CONVENIOS).
 */
final class GestionConvenios
{
    public const MISMO_MONTO = 'El monto nuevo es igual al vigente.';

    public const NOMBRE_MAXIMO = 150;

    public const PLAN_MAXIMO = 100;

    /** @param  list<string>  $planes */
    public static function alta(User $usuario, int $responsableId, string $nombre, Carbon $desde, ?Carbon $hasta, array $planes): Convenio
    {
        $nombre = trim($nombre);
        $planes = self::planesValidos($planes);
        if ($hasta !== null && $hasta->lt($desde)) {
            throw new AccionRechazada('La fecha de fin no puede ser anterior a la de inicio.');
        }

        return DB::transaction(function () use ($usuario, $responsableId, $nombre, $desde, $hasta, $planes) {
            $responsable = ResponsablePago::query()->whereKey($responsableId)->first();
            if ($responsable === null || ! $responsable->estaActivo()) {
                throw new AccionRechazada('El responsable de pago no existe o no está activo.');
            }

            return Auditoria::conDetalle('Alta del convenio', function () use ($usuario, $responsable, $nombre, $desde, $hasta, $planes) {
                $convenio = Convenio::create([
                    'responsable_pago_id' => $responsable->id, 'nombre' => $nombre, 'vigente_desde' => $desde->format('Y-m-d'),
                    'vigente_hasta' => $hasta?->format('Y-m-d'), 'usuario_id' => $usuario->id,
                ]);
                foreach ($planes as $plan) {
                    PlanConvenio::create(['convenio_id' => $convenio->id, 'nombre' => $plan]);
                }

                return $convenio;
            });
        });
    }

    public static function agregarPlan(Convenio $convenio, string $nombre): PlanConvenio
    {
        [$nombre] = self::planesValidos([$nombre]) ?: throw new AccionRechazada('Escriba el nombre del plan.');

        return DB::transaction(function () use ($convenio, $nombre) {
            $actual = Convenio::query()->whereKey($convenio->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->vigente()) {
                throw new AccionRechazada('El convenio ya no está vigente: no se le agregan planes.');
            }
            if (PlanConvenio::where('convenio_id', $actual->id)->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->exists()) {
                throw new AccionRechazada('Ese plan ya existe en el convenio.');
            }

            return PlanConvenio::create(['convenio_id' => $actual->id, 'nombre' => $nombre]);
        });
    }

    /** Activa o desactiva un plan. Inactivo, no se ofrece al cargar una cobertura; las ya cargadas no cambian. */
    public static function cambiarEstadoPlan(PlanConvenio $plan, bool $activo): void
    {
        $plan->update(['estado_id' => Estado::idDe($activo ? Estado::ACTIVO : Estado::INACTIVO)]);
    }

    /** Pasa el convenio a histórico con su último día (no antes del día anterior a su inicio). */
    public static function pasarAHistorico(Convenio $convenio, Carbon $ultimoDia): Convenio
    {
        return DB::transaction(function () use ($convenio, $ultimoDia) {
            $actual = Convenio::query()->whereKey($convenio->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->vigente()) {
                throw new AccionRechazada('El convenio ya es histórico.');
            }
            if ($ultimoDia->lt($actual->vigente_desde->copy()->subDay())) {
                throw new AccionRechazada('El último día no puede ser anterior al inicio del convenio ('.Fecha::mostrar($actual->vigente_desde).').');
            }

            Auditoria::conDetalle('Convenio pasado a histórico', fn () => $actual->forceFill([
                'estado_id' => Estado::idDe(Estado::HISTORICO), 'vigente_hasta' => $ultimoDia->format('Y-m-d'),
            ])->save());

            return $actual;
        });
    }

    /** Alta o cambio de la tarifa de un procedimiento en el convenio (un solo formulario). */
    public static function guardarTarifa(User $usuario, Convenio $convenio, int $procedimientoId, int $monto, Carbon $desde): TarifaConvenio
    {
        return DB::transaction(function () use ($usuario, $convenio, $procedimientoId, $monto, $desde) {
            // Bloquea el convenio: dos cargas simultáneas del mismo procedimiento se ordenan.
            $actual = Convenio::query()->whereKey($convenio->getKey())->lockForUpdate()->firstOrFail();
            if (! $actual->vigente()) {
                throw new AccionRechazada('El convenio ya no está vigente: no se le cargan tarifas.');
            }
            $clave = fn () => TarifaConvenio::query()->where('convenio_id', $actual->id)->where('procedimiento_id', $procedimientoId);
            $vigente = $clave()->whereNull('vigente_hasta')->lockForUpdate()->first();

            if ($vigente === null) {
                // Alta: después del último día de las cerradas, si hay.
                $ultimo = $clave()->max('vigente_hasta');
                if ($ultimo !== null && $desde->format('Y-m-d') <= Carbon::parse($ultimo)->format('Y-m-d')) {
                    throw new AccionRechazada('La tarifa debe empezar después del '.Fecha::mostrar($ultimo).' (el último día de la anterior).');
                }

                return Auditoria::conDetalle('Alta de la tarifa del convenio', fn () => TarifaConvenio::create([
                    'convenio_id' => $actual->id, 'procedimiento_id' => $procedimientoId, 'monto' => $monto,
                    'moneda_id' => Moneda::guarani()->id, 'vigente_desde' => $desde->format('Y-m-d'), 'usuario_id' => $usuario->id,
                ]));
            }

            $minimo = max(Fecha::hoy()->format('Y-m-d'), $vigente->vigente_desde->format('Y-m-d'));
            if ($desde->format('Y-m-d') < $minimo) {
                throw new AccionRechazada('Ya hay una tarifa para ese procedimiento: la nueva debe empezar el '.Fecha::mostrar($minimo).' o después.');
            }
            if ((float) $vigente->monto === (float) $monto) {
                throw new AccionRechazada(self::MISMO_MONTO);
            }

            return Auditoria::conDetalle('Cambio de tarifa del convenio', function () use ($usuario, $vigente, $monto, $desde) {
                $vigente->forceFill(['estado_id' => Estado::idDe(Estado::HISTORICO), 'vigente_hasta' => $desde->copy()->subDay()->format('Y-m-d')])->save();

                return TarifaConvenio::create([
                    'convenio_id' => $vigente->convenio_id, 'procedimiento_id' => $vigente->procedimiento_id, 'monto' => $monto,
                    'moneda_id' => $vigente->moneda_id, 'vigente_desde' => $desde->format('Y-m-d'), 'reemplaza_a_id' => $vigente->id,
                    'usuario_id' => $usuario->id,
                ]);
            });
        });
    }

    /** El procedimiento deja de figurar en el convenio: cierra la tarifa con su último día (ayer o después). */
    public static function bajaTarifa(TarifaConvenio $tarifa, Carbon $ultimoDia): TarifaConvenio
    {
        return DB::transaction(function () use ($tarifa, $ultimoDia) {
            $vigente = TarifaConvenio::query()->whereKey($tarifa->getKey())->lockForUpdate()->firstOrFail();
            if (! $vigente->vigente()) {
                throw new AccionRechazada('Esta tarifa ya no está vigente.');
            }
            $minimo = max(Fecha::hoy()->subDay()->format('Y-m-d'), $vigente->vigente_desde->copy()->subDay()->format('Y-m-d'));
            if ($ultimoDia->format('Y-m-d') < $minimo) {
                throw new AccionRechazada('El último día debe ser el '.Fecha::mostrar($minimo).' o después.');
            }

            Auditoria::conDetalle('Baja de la tarifa del convenio', fn () => $vigente->forceFill([
                'estado_id' => Estado::idDe(Estado::HISTORICO), 'vigente_hasta' => $ultimoDia->format('Y-m-d'),
            ])->save());

            return $vigente;
        });
    }

    /**
     * Los nombres de planes (uno por línea), sin vacíos ni repetidos.
     *
     * @param  list<string>  $planes
     * @return list<string>
     */
    private static function planesValidos(array $planes): array
    {
        $limpios = [];
        foreach ($planes as $plan) {
            $plan = trim((string) $plan);
            if ($plan === '') {
                continue;
            }
            if (mb_strlen($plan) > self::PLAN_MAXIMO) {
                throw new AccionRechazada('El nombre de cada plan puede tener hasta '.self::PLAN_MAXIMO.' caracteres.');
            }
            $limpios[mb_strtolower($plan)] ??= $plan;
        }

        return array_values($limpios);
    }
}
