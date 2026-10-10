<?php

namespace App\Support\Atencion;

use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Guardar cambios" de una consulta FINALIZADA: sin autoguardado, con la validación completa (ya hecha) y
 * el control de concurrencia. Solo el profesional que la atendió, con EDITAR. Los cambios quedan en el
 * historial como EDITAR de la consulta.
 */
final class GuardarCambios
{
    /** @param  array<string, mixed>  $datos */
    public static function ejecutar(User $usuario, Consulta $consulta, array $datos, string $version): void
    {
        DB::transaction(function () use ($usuario, $consulta, $datos, $version) {
            $actual = Apoyo::consultaBloqueada($consulta);
            Gate::forUser($usuario)->authorize('update', $actual);
            if (! $actual->finalizada()) {
                throw new AccionRechazada('Esta consulta no está finalizada.');
            }
            if ($actual->version() !== $version) {
                throw new AccionRechazada(Autoguardado::VERSION_VIEJA);
            }

            Auditoria::conDetalle('Guardar cambios', fn () => GuardarSecciones::guardar($consulta, $datos, $usuario, [FormularioConsulta::PREPARACION, FormularioConsulta::CLINICO]));
            $consulta->touch(); // nueva versión aunque solo hayan cambiado las secciones
        });
    }
}
