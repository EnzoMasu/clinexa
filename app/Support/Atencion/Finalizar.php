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
 * finales) y la consulta pasa a FINALIZADO (finalizada_en). El turno pasa a ATENDIDO si sigue
 * EN_CONSULTA; si cambió por otra vía, la consulta se finaliza igual, el turno queda como está, el evento
 * lo dice en su detalle y el profesional recibe un aviso. Todo queda en UN EDITAR "Finalizar" de la
 * consulta (secciones que cambiaron y estado). Si la consulta ya no está EN_CURSO (Deshacer en otra
 * pestaña), 409 y no se finaliza. Un segundo envío encuentra la consulta finalizada y no hace nada.
 * Devuelve los avisos: el del turno y el de las recetas en borrador (no bloquean).
 */
final class Finalizar
{
    public const NO_EN_CURSO = 'Esta consulta ya no está en curso (se deshizo la atención desde otra ventana). No se finalizó: vuelva a la lista.';

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
            abort_unless($consulta->enCurso(), 409, self::NO_EN_CURSO);
            Gate::forUser($usuario)->authorize('finalizar', $consulta);
            if ($consulta->version() !== $version) {
                throw new AccionRechazada(Autoguardado::VERSION_VIEJA);
            }

            $turno = $consulta->turno_id ? Turno::with('estado')->whereKey($consulta->turno_id)->lockForUpdate()->first() : null;
            $atiende = $turno?->tieneEstado(Estado::EN_CONSULTA) ?? false;
            $detalle = match (true) {
                $turno === null => 'Finalizar',
                $atiende => 'Finalizar (turno ATENDIDO)',
                default => "Finalizar (turno {$turno->estado->nombre}, sin cambios)",
            };

            return Auditoria::conDetalle($detalle, fn () => Auditoria::agrupar(function () use ($usuario, $consulta, $datos, $turno, $atiende) {
                GuardarSecciones::guardar($consulta, $datos, $usuario, [FormularioConsulta::PREPARACION, FormularioConsulta::CLINICO]);
                $consulta->forceFill(['estado_id' => Estado::idDe(Estado::FINALIZADO), 'finalizada_en' => now()])->save();
                if ($atiende) {
                    $turno->pasarA(Estado::ATENDIDO);
                }

                $avisos = $turno && ! $atiende
                    ? ['El turno de las '.substr($turno->hora_inicio, 0, 5)." estaba en estado {$turno->estado->nombre} y no se marcó como atendido. Revíselo en Turnos."]
                    : [];
                $borradores = $consulta->recetas()->where('estado_id', Estado::idDe(Estado::PENDIENTE))->count();

                return [...$avisos, ...match (true) {
                    $borradores === 1 => ['Quedó 1 receta en borrador sin emitir: revísela en la consulta.'],
                    $borradores > 1 => ["Quedaron {$borradores} recetas en borrador sin emitir: revíselas en la consulta."],
                    default => [],
                }];
            }));
        });
    }
}
