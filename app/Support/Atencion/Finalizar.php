<?php

namespace App\Support\Atencion;

use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Turno;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Finalizar: con consulta y turno bloqueados, guarda lo último del formulario (validado con las reglas
 * finales), la consulta pasa a FINALIZADO (finalizada_en) y el turno a ATENDIDO, si sigue EN_CONSULTA; si
 * cambió por otra vía, no se finaliza y se avisa. Un segundo envío encuentra la consulta finalizada y no
 * hace nada. Devuelve las advertencias: las recetas en borrador no bloquean.
 */
final class Finalizar
{
    /**
     * @param  array<string, mixed>  $datos
     * @return list<string>
     */
    public static function ejecutar(User $usuario, Consulta $consulta, array $datos, string $version): array
    {
        return DB::transaction(function () use ($usuario, $consulta, $datos, $version) {
            $consulta = Apoyo::consultaBloqueada($consulta);
            if ($consulta->finalizada()) {
                return []; // doble envío
            }
            Gate::forUser($usuario)->authorize('finalizar', $consulta);
            if ($consulta->version() !== $version) {
                throw new AccionRechazada(Autoguardado::VERSION_VIEJA);
            }

            // El turno pasa a ATENDIDO solo si sigue EN_CONSULTA (la consulta se finaliza igual).
            $turno = $consulta->turno_id ? Turno::whereKey($consulta->turno_id)->lockForUpdate()->first() : null;

            return Auditoria::conDetalle('Finalizar', function () use ($usuario, $consulta, $datos, $turno) {
                GuardarSecciones::guardar($consulta, $datos, $usuario, [FormularioConsulta::PREPARACION, FormularioConsulta::CLINICO]);
                $consulta->forceFill(['estado_id' => Estado::idDe(Estado::FINALIZADO), 'finalizada_en' => now()])->save();
                if ($turno?->tieneEstado(Estado::EN_CONSULTA)) {
                    $turno->pasarA(Estado::ATENDIDO);
                }

                $borradores = $consulta->recetas()->where('estado_id', Estado::idDe(Estado::PENDIENTE))->count();

                return match (true) {
                    $borradores === 1 => ['Quedó 1 receta en borrador sin emitir: revísela en la consulta.'],
                    $borradores > 1 => ["Quedaron {$borradores} recetas en borrador sin emitir: revíselas en la consulta."],
                    default => [],
                };
            });
        });
    }
}
