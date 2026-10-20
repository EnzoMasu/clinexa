<?php

namespace App\Support\Tarifas;

use App\Exceptions\AccionRechazada;
use App\Models\Estado;
use App\Models\Tarifa;
use App\Models\User;
use App\Support\Auditoria;
use App\Support\Fecha;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Cambiar tarifa": no edita. Con la vigente bloqueada, la cierra (HISTORICO, con "hasta" = el día anterior a la nueva) y abre
 * otra VIGENTE desde hoy o una fecha posterior (y no antes de que empiece la que se cierra). Si la nueva empieza el mismo día
 * que la cerrada, la cerrada queda sin vigencia. En Auditoría: EDITAR de la cerrada y CREAR de la nueva, con el detalle
 * "Cambio de tarifa".
 */
final class CambiarTarifa
{
    public const YA_NO_VIGENTE = 'Esta tarifa ya no está vigente (se cambió desde otra ventana). Vuelva al listado.';

    public const MISMO_MONTO = 'El monto nuevo es igual al vigente.';

    public static function ejecutar(User $usuario, Tarifa $tarifa, int $monto, Carbon $desde): Tarifa
    {
        return DB::transaction(function () use ($usuario, $tarifa, $monto, $desde) {
            $vigente = Tarifa::query()->whereKey($tarifa->getKey())->lockForUpdate()->firstOrFail();
            if (! $vigente->vigente()) {
                throw new AccionRechazada(self::YA_NO_VIGENTE);
            }
            $minimo = max(Fecha::hoy()->format('Y-m-d'), $vigente->vigente_desde->format('Y-m-d'));
            if ($desde->format('Y-m-d') < $minimo) {
                throw new AccionRechazada('La nueva tarifa debe empezar el '.Fecha::mostrar($minimo).' o después.');
            }
            if ((float) $vigente->monto === (float) $monto) {
                throw new AccionRechazada(self::MISMO_MONTO);
            }

            return Auditoria::conDetalle('Cambio de tarifa', function () use ($usuario, $vigente, $monto, $desde) {
                $vigente->forceFill([
                    'estado_id' => Estado::idDe(Estado::HISTORICO),
                    'vigente_hasta' => $desde->copy()->subDay()->format('Y-m-d'),
                ])->save();

                return Tarifa::create([
                    'procedimiento_id' => $vigente->procedimiento_id,
                    'profesional_id' => $vigente->profesional_id,
                    'monto' => $monto,
                    'moneda_id' => $vigente->moneda_id,
                    'vigente_desde' => $desde->format('Y-m-d'),
                    'reemplaza_a_id' => $vigente->id,
                    'usuario_id' => $usuario->id,
                ]);
            });
        });
    }
}
