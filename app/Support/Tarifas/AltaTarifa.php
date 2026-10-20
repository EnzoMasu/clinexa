<?php

namespace App\Support\Tarifas;

use App\Exceptions\AccionRechazada;
use App\Models\Moneda;
use App\Models\Tarifa;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La primera tarifa de un procedimiento para un profesional (o la general). Si ya hay una para esa combinación, no se da de
 * alta otra: se usa "Cambiar tarifa". Queda en Auditoría como CREAR.
 */
final class AltaTarifa
{
    public const YA_EXISTE = 'Ya hay una tarifa para ese procedimiento y profesional. Use "Cambiar tarifa" para modificar el monto.';

    public static function ejecutar(User $usuario, int $procedimientoId, ?int $profesionalId, int $monto, Carbon $desde): Tarifa
    {
        return DB::transaction(function () use ($usuario, $procedimientoId, $profesionalId, $monto, $desde) {
            // Bloquea el procedimiento: dos altas simultáneas de la misma combinación se ordenan (y la segunda se rechaza).
            DB::table('procedimientos')->where('id', $procedimientoId)->lockForUpdate()->first();
            if (Tarifa::query()->deLaClave($procedimientoId, $profesionalId)->exists()) {
                throw new AccionRechazada(self::YA_EXISTE);
            }

            return Tarifa::create([
                'procedimiento_id' => $procedimientoId,
                'profesional_id' => $profesionalId,
                'monto' => $monto,
                'moneda_id' => Moneda::guarani()->id,
                'vigente_desde' => $desde->format('Y-m-d'),
                'usuario_id' => $usuario->id,
            ]);
        });
    }
}
