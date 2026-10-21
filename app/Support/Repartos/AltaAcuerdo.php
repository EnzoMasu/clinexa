<?php

namespace App\Support\Repartos;

use App\Exceptions\AccionRechazada;
use App\Models\AcuerdoReparto;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El primer acuerdo de una clave: del profesional (general o de un procedimiento) o del dueño de un equipo. Si ya hay uno para
 * esa clave, no se da de alta otro: se usa "Cambiar porcentaje". Queda en Auditoría como CREAR.
 */
final class AltaAcuerdo
{
    public const YA_EXISTE = 'Ya hay un acuerdo para esa combinación. Use "Cambiar porcentaje" para modificarlo.';

    public static function ejecutar(User $usuario, string $tipo, ?int $profesionalId, ?int $procedimientoId, ?int $equipoId, string $porcentaje, Carbon $desde): AcuerdoReparto
    {
        $porcentaje = Porcentaje::validado($porcentaje);
        if ($tipo === AcuerdoReparto::EQUIPO) {
            [$profesionalId, $procedimientoId] = [null, null];
        } else {
            $equipoId = null;
        }

        return DB::transaction(function () use ($usuario, $tipo, $profesionalId, $procedimientoId, $equipoId, $porcentaje, $desde) {
            // Bloquea al beneficiario: dos altas simultáneas de la misma clave se ordenan (y la segunda se rechaza).
            $tipo === AcuerdoReparto::EQUIPO
                ? DB::table('equipos')->where('id', $equipoId)->lockForUpdate()->first()
                : DB::table('profesionales')->where('id', $profesionalId)->lockForUpdate()->first();
            if (AcuerdoReparto::query()->deLaClave($tipo, $profesionalId, $procedimientoId, $equipoId)->exists()) {
                throw new AccionRechazada(self::YA_EXISTE);
            }

            return AcuerdoReparto::create([
                'tipo' => $tipo,
                'profesional_id' => $profesionalId,
                'procedimiento_id' => $procedimientoId,
                'equipo_id' => $equipoId,
                'porcentaje' => $porcentaje,
                'vigente_desde' => $desde->format('Y-m-d'),
                'usuario_id' => $usuario->id,
            ]);
        });
    }
}
