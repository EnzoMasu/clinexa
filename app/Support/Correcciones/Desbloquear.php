<?php

namespace App\Support\Correcciones;

use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\DesbloqueoConsulta;
use App\Models\Estado;
use App\Models\User;
use App\Support\Atencion\Apoyo;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Abre y cancela la ventana de corrección de una consulta cerrada. Abrir: con la consulta bloqueada,
 * ConsultaPolicy::desbloquear revalidada adentro (EDITAR sobre CORRECCIONES_HC y nunca quien la atendió) y una
 * sola ventana VIGENTE por consulta. Vigente 24 horas. Cancelar: solo mientras está VIGENTE.
 */
final class Desbloquear
{
    public const YA_HAY_UNO = 'Esta consulta ya tiene un desbloqueo vigente.';

    public static function abrir(User $usuario, Consulta $consulta, string $motivo): DesbloqueoConsulta
    {
        return DB::transaction(function () use ($usuario, $consulta, $motivo) {
            $consulta = Apoyo::consultaBloqueada($consulta);
            Gate::forUser($usuario)->authorize('desbloquear', $consulta);
            if (DesbloqueoConsulta::vigenteDe($consulta->id)) {
                throw new AccionRechazada(self::YA_HAY_UNO);
            }

            return DesbloqueoConsulta::create([
                'consulta_id' => $consulta->id, 'motivo' => trim($motivo), 'usuario_id' => $usuario->id,
                'vence_en' => now()->addHours(DesbloqueoConsulta::HORAS_DE_VIGENCIA),
            ]);
        });
    }

    public static function cancelar(User $usuario, DesbloqueoConsulta $desbloqueo): void
    {
        DB::transaction(function () use ($usuario, $desbloqueo) {
            $consulta = Apoyo::consultaBloqueada($desbloqueo->consulta);
            Gate::forUser($usuario)->authorize('desbloquear', $consulta);
            $desbloqueo = DesbloqueoConsulta::whereKey($desbloqueo->id)->lockForUpdate()->firstOrFail();
            if (! $desbloqueo->estaVigente()) {
                throw new AccionRechazada('Este desbloqueo ya no está vigente.');
            }
            Auditoria::conDetalle('Desbloqueo cancelado', fn () => $desbloqueo->forceFill(['estado_id' => Estado::idDe(Estado::CANCELADO), 'cerrado_por_id' => $usuario->id])->save());
        });
    }
}
