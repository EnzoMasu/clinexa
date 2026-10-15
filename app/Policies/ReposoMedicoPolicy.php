<?php

namespace App\Policies;

use App\Models\Consulta;
use App\Models\ReposoMedico;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Certificado de reposo médico (REPOSO_MEDICO; docs/reposo-medico.md). Además del permiso del módulo, rige quién es:
 *
 * - Emitir (CREAR): SOLO el profesional ACTIVO de la consulta, con la consulta en curso o finalizada. Ni el Administrador
 *   ni Supervisión médica emiten, aunque tengan CREAR por la matriz.
 * - Ver y sacar el PDF (VER): el profesional de la consulta, el Administrador y Supervisión médica (quien tiene VER sobre
 *   CORRECCIONES_HC, el permiso que la identifica en el sistema).
 * - Anular (EDITAR): el profesional que lo emitió y el Administrador; solo uno EMITIDO.
 *
 * Recepción no tiene el módulo: no ve nada.
 */
class ReposoMedicoPolicy
{
    public const MODULO = 'REPOSO_MEDICO';

    public const SOLO_EL_PROFESIONAL = 'Solo el profesional de la consulta puede emitir un certificado de reposo.';

    public function __construct(private ConsultaPolicy $consultas) {}

    /** La pantalla de los certificados de una consulta (lista y, si corresponde, el formulario). */
    public function verDeConsulta(User $usuario, Consulta $consulta): bool
    {
        return $usuario->tienePermiso(self::MODULO, 'VER') && $this->consultaAdmite($consulta) && $this->puedeLeer($usuario, $consulta);
    }

    public function ver(User $usuario, ReposoMedico $reposo): bool
    {
        return $usuario->tienePermiso(self::MODULO, 'VER')
            && ((int) $reposo->usuario_id === (int) $usuario->id || $this->puedeLeer($usuario, $reposo->consulta));
    }

    public function emitir(User $usuario, Consulta $consulta): Response
    {
        if (! $usuario->tienePermiso(self::MODULO, 'CREAR')) {
            return Response::deny('No tiene permiso para emitir certificados de reposo.');
        }
        if (! $this->consultas->esElQueAtiende($usuario, $consulta)) {
            return Response::deny(self::SOLO_EL_PROFESIONAL);
        }

        return $this->consultaAdmite($consulta) ? Response::allow() : Response::deny('El reposo se emite solo de una consulta en curso o finalizada.');
    }

    public function anular(User $usuario, ReposoMedico $reposo): Response
    {
        if (! $usuario->tienePermiso(self::MODULO, 'EDITAR')) {
            return Response::deny('No tiene permiso para anular certificados de reposo.');
        }
        if (! $usuario->esAdministrador() && (int) $reposo->usuario_id !== (int) $usuario->id) {
            return Response::deny('Solo el profesional que emitió el certificado o el Administrador pueden anularlo.');
        }

        return $reposo->estaEmitido() ? Response::allow() : Response::deny('Este certificado ya está anulado.');
    }

    private function consultaAdmite(Consulta $consulta): bool
    {
        return $consulta->enCurso() || $consulta->finalizada();
    }

    private function puedeLeer(User $usuario, Consulta $consulta): bool
    {
        return $usuario->esAdministrador() || $usuario->tienePermiso('CORRECCIONES_HC', 'VER') || $this->consultas->esElQueAtiende($usuario, $consulta);
    }
}
