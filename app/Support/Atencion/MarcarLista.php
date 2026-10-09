<?php

namespace App\Support\Atencion;

use App\Models\Consulta;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Marcar la preparación como lista (preparada_en = ahora) o reabrirla (null). Solo EN_PREPARACION. */
final class MarcarLista
{
    public static function ejecutar(User $usuario, Consulta $consulta, bool $lista): void
    {
        DB::transaction(function () use ($usuario, $consulta, $lista) {
            $consulta = Apoyo::consultaBloqueada($consulta);
            Gate::forUser($usuario)->authorize('marcarLista', $consulta);

            Auditoria::conDetalle($lista ? 'Marcar como lista' : 'Reabrir la preparación',
                fn () => $consulta->forceFill(['preparada_en' => $lista ? now() : null])->save());
        });
    }
}
