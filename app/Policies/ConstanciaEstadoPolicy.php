<?php

namespace App\Policies;

use App\Models\ConstanciaEstado;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Constancia de estado del turno (CONSTANCIAS_ESTADO). Además del permiso del módulo, rige siempre quién es:
 * el profesional ACTIVO del turno, o Supervisión médica (quien tiene VER sobre CORRECCIONES_HC, el permiso que la
 * identifica en el sistema). Ni el Médico de otro turno la emite, la anula ni la lee.
 *
 * - Emitir: CREAR y un turno con un hecho para certificar (ConstanciaEstado::ESTADOS_CON_CONSTANCIA).
 * - Anular: EDITAR y una constancia emitida. Leer: VER.
 */
class ConstanciaEstadoPolicy
{
    public const MODULO = 'CONSTANCIAS_ESTADO';

    public const SOLO_SU_TURNO = 'Solo el profesional del turno o Supervisión médica puede emitir esta constancia.';

    public function ver(User $usuario, ConstanciaEstado $constancia): bool
    {
        return $usuario->tienePermiso(self::MODULO, 'VER') && $this->esDelTurnoOSupervisa($usuario, $constancia->turno);
    }

    public function emitir(User $usuario, Turno $turno): Response
    {
        if (! $usuario->tienePermiso(self::MODULO, 'CREAR')) {
            return Response::deny('No tiene permiso para emitir constancias de estado.');
        }
        if (! $this->esDelTurnoOSupervisa($usuario, $turno)) {
            return Response::deny(self::SOLO_SU_TURNO);
        }

        return ($motivo = ConstanciaEstado::motivoDeNoAplicar($turno)) ? Response::deny($motivo) : Response::allow();
    }

    public function anular(User $usuario, ConstanciaEstado $constancia): Response
    {
        if (! $usuario->tienePermiso(self::MODULO, 'EDITAR')) {
            return Response::deny('No tiene permiso para anular constancias de estado.');
        }
        if (! $this->esDelTurnoOSupervisa($usuario, $constancia->turno)) {
            return Response::deny(self::SOLO_SU_TURNO);
        }

        return $constancia->estaEmitida() ? Response::allow() : Response::deny('Esta constancia ya está anulada.');
    }

    private function esDelTurnoOSupervisa(User $usuario, Turno $turno): bool
    {
        if ($usuario->tienePermiso('CORRECCIONES_HC', 'VER')) {
            return true;
        }
        $profesional = $usuario->profesional;

        return $profesional !== null && $profesional->estaActivo() && (int) $profesional->id === (int) $turno->profesional_id;
    }
}
