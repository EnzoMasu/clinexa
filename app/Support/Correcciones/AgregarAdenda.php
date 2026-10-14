<?php

namespace App\Support\Correcciones;

use App\Models\AdendaConsulta;
use App\Models\Consulta;
use App\Models\User;
use App\Support\Atencion\Apoyo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Agrega una adenda a una consulta FINALIZADA: con la consulta bloqueada y la autorización revalidada adentro
 * (ConsultaPolicy::agregarAdenda). La adenda no toca la consulta ni nada de lo suyo. Queda en la auditoría
 * como CREAR de adendas_consulta.
 */
final class AgregarAdenda
{
    public static function ejecutar(User $usuario, Consulta $consulta, string $motivo, string $texto): AdendaConsulta
    {
        return DB::transaction(function () use ($usuario, $consulta, $motivo, $texto) {
            $consulta = Apoyo::consultaBloqueada($consulta);
            Gate::forUser($usuario)->authorize('agregarAdenda', $consulta);

            return AdendaConsulta::create(['consulta_id' => $consulta->id, 'motivo' => trim($motivo), 'texto' => trim($texto), 'usuario_id' => $usuario->id]);
        });
    }
}
