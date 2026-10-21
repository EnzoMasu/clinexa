<?php

namespace App\Support\Repartos;

use App\Exceptions\AccionRechazada;
use App\Models\AcuerdoReparto;
use App\Models\Estado;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Cambiar porcentaje": no edita. Como "Cambiar tarifa": con el vigente bloqueado, lo cierra (HISTORICO, "hasta" = el día
 * anterior al nuevo) y abre otro VIGENTE desde hoy o una fecha posterior (y no antes de que empiece el que se cierra). Los
 * repartos ya hechos no cambian. En Auditoría: EDITAR del cerrado y CREAR del nuevo, con el detalle "Cambio de porcentaje".
 */
final class CambiarAcuerdo
{
    public const YA_NO_VIGENTE = 'Este acuerdo ya no está vigente (se cambió desde otra ventana). Vuelva al listado.';

    public const MISMO_PORCENTAJE = 'El porcentaje nuevo es igual al vigente.';

    public static function ejecutar(User $usuario, AcuerdoReparto $acuerdo, string $porcentaje, Carbon $desde): AcuerdoReparto
    {
        $porcentaje = Porcentaje::validado($porcentaje);

        return DB::transaction(function () use ($usuario, $acuerdo, $porcentaje, $desde) {
            $vigente = AcuerdoReparto::query()->whereKey($acuerdo->getKey())->lockForUpdate()->firstOrFail();
            if (! $vigente->vigente()) {
                throw new AccionRechazada(self::YA_NO_VIGENTE);
            }
            $minimo = max(Fecha::hoy()->format('Y-m-d'), $vigente->vigente_desde->format('Y-m-d'));
            if ($desde->format('Y-m-d') < $minimo) {
                throw new AccionRechazada('El nuevo porcentaje debe empezar el '.Fecha::mostrar($minimo).' o después.');
            }
            if (Porcentaje::centesimas($vigente->porcentaje) === Porcentaje::centesimas($porcentaje)) {
                throw new AccionRechazada(self::MISMO_PORCENTAJE);
            }

            return Auditoria::conDetalle('Cambio de porcentaje', function () use ($usuario, $vigente, $porcentaje, $desde) {
                $vigente->forceFill([
                    'estado_id' => Estado::idDe(Estado::HISTORICO),
                    'vigente_hasta' => $desde->copy()->subDay()->format('Y-m-d'),
                ])->save();

                return AcuerdoReparto::create([
                    'tipo' => $vigente->tipo,
                    'profesional_id' => $vigente->profesional_id,
                    'procedimiento_id' => $vigente->procedimiento_id,
                    'equipo_id' => $vigente->equipo_id,
                    'porcentaje' => $porcentaje,
                    'vigente_desde' => $desde->format('Y-m-d'),
                    'reemplaza_a_id' => $vigente->id,
                    'usuario_id' => $usuario->id,
                ]);
            });
        });
    }
}
