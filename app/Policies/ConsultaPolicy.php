<?php

namespace App\Policies;

use App\Models\Consulta;
use App\Models\Estado;
use App\Models\HistoriaClinica;
use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;
use App\Support\Fecha;
use Illuminate\Auth\Access\Response;

/**
 * Quién crea y quién modifica una consulta de la historia clínica (el único lugar que lo decide).
 *
 * - Leer: cualquiera con VER sobre HISTORIA_CLINICA (lo controla la ruta).
 * - Crear: un profesional ACTIVO (users.persona_id = profesionales.persona_id) con CREAR, para un
 *   paciente ACTIVO. Desde un turno: el turno tiene que ser suyo, de ese paciente, CONFIRMADO, de
 *   hoy (hora de Paraguay) y sin consulta.
 * - Modificar: solo el profesional que la atiende, ACTIVO y con EDITAR. Ni el Administrador
 *   modifica la consulta de otro profesional. El paciente puede estar inactivo (se corrige igual).
 */
class ConsultaPolicy
{
    public const SOLO_EL_QUE_ATIENDE = 'Solo el profesional que atiende puede modificar esta consulta.';

    public function create(User $usuario, HistoriaClinica $historia, ?Turno $turno = null): Response
    {
        if (! $usuario->tienePermiso('HISTORIA_CLINICA', 'CREAR')) {
            return Response::deny('No tiene permiso para crear consultas.');
        }

        $profesional = $this->profesionalActivo($usuario);
        if (! $profesional) {
            return Response::deny('Solo un profesional activo puede atender consultas.');
        }

        if (! $historia->paciente->estaActivo()) {
            return Response::deny('El paciente está inactivo: no se pueden cargar consultas nuevas.');
        }

        return $turno ? $this->puedeAtenderTurno($profesional, $historia, $turno) : Response::allow();
    }

    public function update(User $usuario, Consulta $consulta): Response
    {
        $profesional = $this->profesionalActivo($usuario);

        return $usuario->tienePermiso('HISTORIA_CLINICA', 'EDITAR') && $profesional && (int) $profesional->id === (int) $consulta->profesional_id
            ? Response::allow()
            : Response::deny(self::SOLO_EL_QUE_ATIENDE);
    }

    /** El turno se puede atender ahora: suyo, de este paciente, CONFIRMADO, de hoy y sin consulta. */
    private function puedeAtenderTurno(Profesional $profesional, HistoriaClinica $historia, Turno $turno): Response
    {
        return match (true) {
            (int) $turno->profesional_id !== (int) $profesional->id => Response::deny('Solo el profesional del turno puede atenderlo.'),
            (int) $turno->paciente_id !== (int) $historia->paciente_id => Response::deny('El turno no es de este paciente.'),
            ! $turno->tieneEstado(Estado::CONFIRMADO) => Response::deny('Solo se puede atender un turno confirmado.'),
            $turno->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d') => Response::deny('Solo se pueden atender los turnos de hoy.'),
            ($turno->relationLoaded('consulta') ? $turno->consulta !== null : $turno->consulta()->exists()) => Response::deny('Este turno ya tiene una consulta.'),
            default => Response::allow(),
        };
    }

    /** El profesional del usuario, si está ACTIVO (se carga una vez por usuario). */
    private function profesionalActivo(User $usuario): ?Profesional
    {
        $profesional = $usuario->relationLoaded('profesional') ? $usuario->profesional : $usuario->load('profesional')->profesional;

        return $profesional?->estaActivo() ? $profesional : null;
    }
}
