<?php

namespace App\Policies;

use App\Http\Controllers\Admin\RecetaController;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\Receta;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Quién escribe en las recetas de una consulta. Leer (la sección, la vista previa y la hoja) pide VER
 * sobre RECETAS, que controla la ruta. Para escribir, además del permiso de RECETAS que corresponde,
 * rige siempre la regla de la consulta: solo el profesional ACTIVO que la atiende
 * (ConsultaPolicy::esElQueAtiende), con el mismo mensaje. Ni el Administrador escribe en las recetas
 * de otro profesional.
 *
 * - Crear un borrador y emitirlo: CREAR.
 * - Editar un borrador y anular: EDITAR.
 * - Anular y corregir (anula y crea el borrador de reemplazo): EDITAR y CREAR.
 */
class RecetaPolicy
{
    public function __construct(private readonly ConsultaPolicy $consultas) {}

    public function create(User $usuario, Consulta $consulta): Response
    {
        if (($rechazo = $this->deLaConsulta($usuario, $consulta, ['CREAR'])) !== null) {
            return $rechazo;
        }

        return $consulta->recetas()->where('estado_id', '!=', Estado::idDe(Estado::ANULADO))->count() >= RecetaController::MAXIMO_RECETAS
            ? Response::deny('La consulta ya tiene '.RecetaController::MAXIMO_RECETAS.' recetas sin anular. Anule alguna para crear otra.')
            : Response::allow();
    }

    /** Editar el borrador (renglones y observaciones). */
    public function update(User $usuario, Receta $receta): Response
    {
        return $this->deLaConsulta($usuario, $receta->consulta, ['EDITAR'])
            ?? ($receta->esBorrador() ? Response::allow() : Response::deny('Una receta emitida o anulada no se modifica. Para corregirla, anúlela y emita otra.'));
    }

    public function emitir(User $usuario, Receta $receta): Response
    {
        return $this->deLaConsulta($usuario, $receta->consulta, ['CREAR']) ?? Response::allow();
    }

    public function anular(User $usuario, Receta $receta): Response
    {
        return $this->deLaConsulta($usuario, $receta->consulta, ['EDITAR'])
            ?? ($receta->puedePasarA(Estado::ANULADO) ? Response::allow() : Response::deny('Esta receta ya está anulada.'));
    }

    /** Anular una EMITIDA y crear el borrador que la reemplaza. */
    public function corregir(User $usuario, Receta $receta): Response
    {
        return $this->deLaConsulta($usuario, $receta->consulta, ['EDITAR', 'CREAR'])
            ?? ($receta->estaEmitida() ? Response::allow() : Response::deny('Solo se corrige una receta emitida.'));
    }

    /** El rechazo por permisos de RECETAS o por no ser el profesional que atiende, o null si pasa. */
    private function deLaConsulta(User $usuario, Consulta $consulta, array $acciones): ?Response
    {
        foreach ($acciones as $accion) {
            if (! $usuario->tienePermiso('RECETAS', $accion)) {
                return Response::deny('No tiene permiso para esta acción sobre las recetas.');
            }
        }

        return $this->consultas->esElQueAtiende($usuario, $consulta) ? null : Response::deny(ConsultaPolicy::SOLO_EL_QUE_ATIENDE);
    }
}
