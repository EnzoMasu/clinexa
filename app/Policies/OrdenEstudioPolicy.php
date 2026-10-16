<?php

namespace App\Policies;

use App\Models\Consulta;
use App\Models\OrdenEstudio;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Orden de estudio (ORDENES_ESTUDIO; docs/ordenes-estudio.md). Misma regla que el reposo médico: además del permiso del
 * módulo, rige quién es:
 *
 * - Emitir (CREAR): SOLO el profesional ACTIVO de la consulta, con la consulta en curso o finalizada. Ni el Administrador
 *   ni Supervisión médica emiten, aunque tengan CREAR por la matriz.
 * - Ver y sacar el PDF (VER): el profesional de la consulta, el Administrador y Supervisión médica (quien tiene VER sobre
 *   CORRECCIONES_HC, el permiso que la identifica en el sistema).
 * - Anular (EDITAR): el profesional que la emitió y el Administrador; solo una EMITIDA.
 *
 * Recepción no tiene el módulo: no ve nada.
 */
class OrdenEstudioPolicy
{
    public const MODULO = 'ORDENES_ESTUDIO';

    public const SOLO_EL_PROFESIONAL = 'Solo el profesional de la consulta puede emitir una orden de estudio.';

    public function __construct(private ConsultaPolicy $consultas) {}

    /** La pantalla de las órdenes de una consulta (lista y, si corresponde, el formulario). */
    public function verDeConsulta(User $usuario, Consulta $consulta): bool
    {
        return $usuario->tienePermiso(self::MODULO, 'VER') && $this->consultaAdmite($consulta) && $this->puedeLeer($usuario, $consulta);
    }

    public function ver(User $usuario, OrdenEstudio $orden): bool
    {
        return $usuario->tienePermiso(self::MODULO, 'VER')
            && ((int) $orden->usuario_id === (int) $usuario->id || $this->puedeLeer($usuario, $orden->consulta));
    }

    public function emitir(User $usuario, Consulta $consulta): Response
    {
        if (! $usuario->tienePermiso(self::MODULO, 'CREAR')) {
            return Response::deny('No tiene permiso para emitir órdenes de estudio.');
        }
        if (! $this->consultas->esElQueAtiende($usuario, $consulta)) {
            return Response::deny(self::SOLO_EL_PROFESIONAL);
        }

        return $this->consultaAdmite($consulta) ? Response::allow() : Response::deny('La orden de estudio se emite solo de una consulta en curso o finalizada.');
    }

    public function anular(User $usuario, OrdenEstudio $orden): Response
    {
        if (! $usuario->tienePermiso(self::MODULO, 'EDITAR')) {
            return Response::deny('No tiene permiso para anular órdenes de estudio.');
        }
        if (! $usuario->esAdministrador() && (int) $orden->usuario_id !== (int) $usuario->id) {
            return Response::deny('Solo el profesional que emitió la orden o el Administrador pueden anularla.');
        }

        return $orden->estaEmitida() ? Response::allow() : Response::deny('Esta orden ya está anulada.');
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
