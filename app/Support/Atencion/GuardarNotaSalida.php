<?php

namespace App\Support\Atencion;

use App\Exceptions\AccionRechazada;
use App\Models\Consulta;
use App\Models\NotaSalida;
use App\Models\SalidaSinAtencion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * La nota clínica del profesional sobre un retiro o rechazo de SU turno: una sola vez (la base lo asegura con un unique
 * y la nota es inmutable). Con la salida bloqueada; ConsultaPolicy::notaDeSalida revalida adentro.
 */
final class GuardarNotaSalida
{
    public const YA_TIENE_NOTA = 'Este registro ya tiene su nota clínica. No se puede modificar.';

    public static function ejecutar(User $usuario, SalidaSinAtencion $salida, string $texto): NotaSalida
    {
        try {
            return DB::transaction(function () use ($usuario, $salida, $texto) {
                $salida = SalidaSinAtencion::with('turno')->whereKey($salida->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($usuario)->authorize('notaDeSalida', [Consulta::class, $salida]);
                if ($salida->nota()->exists()) {
                    throw new AccionRechazada(self::YA_TIENE_NOTA);
                }

                return NotaSalida::create(['salida_id' => $salida->id, 'texto' => $texto, 'profesional_id' => $salida->turno->profesional_id, 'usuario_id' => $usuario->id]);
            });
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23505', '23000'], true)) {
                throw new AccionRechazada(self::YA_TIENE_NOTA);
            }
            throw $e;
        }
    }
}
