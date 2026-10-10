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
 * Quién hace qué con una consulta (el único lugar que lo decide). Leer: VER sobre HISTORIA_CLINICA
 * (lo controla la ruta). Escribir se divide en dos grupos de campos:
 *
 * - PREPARACIÓN (bloques de anamnesis y signos vitales): (a) el profesional ACTIVO de la consulta con
 *   CREAR o EDITAR sobre HISTORIA_CLINICA, o (b) cualquier usuario con EDITAR sobre PREPARACION; solo
 *   mientras la consulta está EN_PREPARACION o EN_CURSO.
 * - CLÍNICO (motivo, hallazgos, diagnósticos, indicaciones y recetas): solo el profesional ACTIVO de la
 *   consulta (ni el Administrador), mientras está EN_CURSO.
 * - Cerrada (FINALIZADA o ANULADA): nadie escribe. La regla de fondo está en los modelos
 *   (ProtegidoPorCierre) y las rutas responden 409 (middleware consulta.abierta).
 *
 * Las acciones del flujo (preparar, atender, no se presentó, cerrar jornada, deshacer, finalizar)
 * también se deciden acá; los servicios de App\Support\Atencion las revalidan dentro de su transacción.
 */
class ConsultaPolicy
{
    public const SOLO_EL_QUE_ATIENDE = 'Solo el profesional que atiende puede modificar esta consulta.';

    /** Estados del turno desde los que se puede preparar o atender. */
    public const TURNO_POR_ATENDER = [Estado::PENDIENTE, Estado::CONFIRMADO, Estado::SALTADO];

    /**
     * La parte de "atender" que depende solo del usuario: profesional ACTIVO con CREAR sobre
     * HISTORIA_CLINICA. Para mostrar las entradas a una atención sin mirar paciente por paciente:
     * Gate::allows('atender', Consulta::class), una vez por pedido.
     */
    public function atender(User $usuario): Response
    {
        if (! $usuario->tienePermiso('HISTORIA_CLINICA', 'CREAR')) {
            return Response::deny('No tiene permiso para crear consultas.');
        }

        return $this->profesionalActivo($usuario)
            ? Response::allow()
            : Response::deny('Solo un profesional activo puede atender consultas.');
    }

    /** Atender sin turno (urgencia) a este paciente: profesional activo con CREAR, paciente activo. */
    public function atenderSinTurno(User $usuario, HistoriaClinica $historia): Response
    {
        $atender = $this->atender($usuario);
        if ($atender->denied()) {
            return $atender;
        }

        return $historia->paciente->estaActivo()
            ? Response::allow()
            : Response::deny('El paciente está inactivo: no se pueden cargar consultas nuevas.');
    }

    /** Atender este turno: el profesional ACTIVO del turno con CREAR, turno de hoy por atender, paciente activo. */
    public function atenderTurno(User $usuario, Turno $turno): Response
    {
        $atender = $this->atender($usuario);
        if ($atender->denied()) {
            return $atender;
        }
        if ((int) $turno->profesional_id !== (int) $this->profesionalActivo($usuario)->id) {
            return Response::deny('Solo el profesional del turno puede atenderlo.');
        }

        return $this->turnoDeHoyPorAtender($turno);
    }

    /**
     * Preparar al paciente de este turno (crear la consulta EN_PREPARACION): CREAR sobre PREPARACION, o
     * ser el profesional ACTIVO del turno con CREAR sobre HISTORIA_CLINICA. Turno de hoy por atender, con
     * paciente y profesional activos.
     */
    public function preparar(User $usuario, Turno $turno): Response
    {
        $esSuProfesional = $usuario->tienePermiso('HISTORIA_CLINICA', 'CREAR')
            && (int) $this->profesionalActivo($usuario)?->id === (int) $turno->profesional_id;
        if (! $usuario->tienePermiso('PREPARACION', 'CREAR') && ! $esSuProfesional) {
            return Response::deny('No tiene permiso para preparar consultas.');
        }
        if (! $turno->profesional->estaActivo()) {
            return Response::deny('El profesional del turno está inactivo.');
        }

        return $this->turnoDeHoyPorAtender($turno);
    }

    /** "No se presentó": el profesional ACTIVO del turno con EDITAR sobre TURNOS; turno de hoy PENDIENTE o CONFIRMADO. */
    public function noSePresento(User $usuario, Turno $turno): Response
    {
        if (! $usuario->tienePermiso('TURNOS', 'EDITAR') || (int) $this->profesionalActivo($usuario)?->id !== (int) $turno->profesional_id) {
            return Response::deny('Solo el profesional del turno puede marcar que no se presentó.');
        }
        if ($turno->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d')) {
            return Response::deny('Solo se marcan los turnos de hoy.');
        }

        return in_array($turno->estado->codigo, [Estado::PENDIENTE, Estado::CONFIRMADO], true)
            ? Response::allow()
            : Response::deny('Solo se marca un turno pendiente o confirmado.');
    }

    /** Cerrar la jornada (sus turnos sin atender pasan a AUSENTE): profesional ACTIVO con EDITAR sobre TURNOS. */
    public function cerrarJornada(User $usuario): Response
    {
        return $usuario->tienePermiso('TURNOS', 'EDITAR') && $this->profesionalActivo($usuario)
            ? Response::allow()
            : Response::deny('Solo un profesional activo con permiso sobre los turnos puede cerrar su jornada.');
    }

    /** Escribir el grupo PREPARACIÓN (anamnesis y signos vitales) de esta consulta. */
    public function escribirPreparacion(User $usuario, Consulta $consulta): Response
    {
        if ($consulta->abierta()) {
            $comoProfesional = $this->esElQueAtiende($usuario, $consulta)
                && ($usuario->tienePermiso('HISTORIA_CLINICA', 'CREAR') || $usuario->tienePermiso('HISTORIA_CLINICA', 'EDITAR'));

            return $comoProfesional || $usuario->tienePermiso('PREPARACION', 'EDITAR')
                ? Response::allow()
                : Response::deny('No tiene permiso para cargar la preparación de esta consulta.');
        }

        return Response::deny(\App\Exceptions\ConsultaCerrada::MENSAJE);
    }

    /** Escribir el grupo CLÍNICO (motivo, hallazgos, diagnósticos, indicaciones y recetas) de esta consulta. */
    public function escribirClinico(User $usuario, Consulta $consulta): Response
    {
        if ($consulta->enCurso()) {
            return $this->esElQueAtiende($usuario, $consulta)
                && ($usuario->tienePermiso('HISTORIA_CLINICA', 'CREAR') || $usuario->tienePermiso('HISTORIA_CLINICA', 'EDITAR'))
                ? Response::allow()
                : Response::deny(self::SOLO_EL_QUE_ATIENDE);
        }

        return Response::deny($consulta->enPreparacion() ? 'Esta consulta todavía no está en curso.' : \App\Exceptions\ConsultaCerrada::MENSAJE);
    }


    /** Marcar como lista o reabrir la preparación: quien escribe el grupo PREPARACIÓN, solo EN_PREPARACION. */
    public function marcarLista(User $usuario, Consulta $consulta): Response
    {
        return $consulta->enPreparacion() ? $this->escribirPreparacion($usuario, $consulta) : Response::deny('La consulta ya no está en preparación.');
    }

    /** Finalizar o deshacer la atención: el profesional de la consulta, EN_CURSO. */
    public function finalizar(User $usuario, Consulta $consulta): Response
    {
        return $consulta->enCurso() ? $this->escribirClinico($usuario, $consulta) : Response::deny('La consulta no está en curso.');
    }

    public function deshacer(User $usuario, Consulta $consulta): Response
    {
        return $this->finalizar($usuario, $consulta);
    }

    /**
     * El usuario es el profesional ACTIVO que atiende la consulta. Es la regla de fondo para escribir
     * en ella (y en sus recetas, RecetaPolicy): ni el Administrador escribe en la consulta de otro.
     */
    public function esElQueAtiende(User $usuario, Consulta $consulta): bool
    {
        $profesional = $this->profesionalActivo($usuario);

        return $profesional !== null && (int) $profesional->id === (int) $consulta->profesional_id;
    }

    /** El turno es de hoy (hora de Paraguay), está por atender y su paciente está activo. */
    private function turnoDeHoyPorAtender(Turno $turno): Response
    {
        return match (true) {
            $turno->fecha->format('Y-m-d') !== Fecha::hoy()->format('Y-m-d') => Response::deny('Solo se atienden los turnos de hoy.'),
            ! in_array($turno->estado->codigo, self::TURNO_POR_ATENDER, true) => Response::deny('Este turno no está por atender (estado: '.mb_strtolower((string) $turno->estado->nombre).').'),
            ! $turno->paciente->estaActivo() => Response::deny('El paciente está inactivo: no se pueden cargar consultas nuevas.'),
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
