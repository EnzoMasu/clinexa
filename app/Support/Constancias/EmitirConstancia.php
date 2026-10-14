<?php

namespace App\Support\Constancias;

use App\Exceptions\AccionRechazada;
use App\Models\Constancia;
use App\Models\ConstanciaRetiro;
use App\Models\Estado;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Emite la constancia de un turno, con el turno bloqueado. Si ya hay una EMITIDA de ese tipo, la devuelve sin emitir otra
 * (doble clic, otra pestaña). Asigna número correlativo (CR-0000001 / CE-0000001), fecha de hoy en hora de Paraguay,
 * snapshot y huella sha256. La autorización (la policy) la hace el llamador; acá se revalida solo que el turno la admita.
 */
final class EmitirConstancia
{
    /** SQLSTATE de clave única repetida (PostgreSQL 23505; SQLite informa 23000). */
    private const CLAVE_REPETIDA = ['23505', '23000'];

    private const INTENTOS_NUMERO = 5;

    /**
     * @param  class-string<Constancia>  $modelo
     * @return array{0: Constancia, 1: bool}  la constancia y si es nueva
     */
    public static function ejecutar(User $usuario, string $modelo, Turno $turno): array
    {
        return DB::transaction(function () use ($usuario, $modelo, $turno) {
            $turno = Turno::with(['estado', 'salida'])->whereKey($turno->id)->lockForUpdate()->firstOrFail();

            $existente = $modelo::where('turno_id', $turno->id)->where('estado_id', Estado::idDe(Estado::EMITIDO))->first();
            if ($existente) {
                return [$existente, false];
            }
            if ($motivo = $modelo::motivoDeNoAplicar($turno)) {
                throw new AccionRechazada($motivo);
            }

            $snapshot = $modelo === ConstanciaRetiro::class
                ? DatosConstancia::retiro($turno, $turno->salida, $usuario)
                : DatosConstancia::estado($turno, $usuario);

            return [self::guardar($modelo, $turno, $usuario, $snapshot), true];
        });
    }

    /** Si otra emisión simultánea tomó el mismo número, el unique de la base lo rechaza y se reintenta con el siguiente. */
    private static function guardar(string $modelo, Turno $turno, User $usuario, array $snapshot): Constancia
    {
        for ($intento = 1; ; $intento++) {
            $numero = $modelo::siguienteNumero();
            $datos = [...$snapshot, 'numero' => $numero];
            try {
                return DB::transaction(function () use ($modelo, $turno, $usuario, $numero, $datos) {
                    $constancia = new $modelo([
                        'numero' => $numero, 'turno_id' => $turno->id, 'fecha' => $datos['fecha'], 'emitida_en' => now(),
                        'snapshot' => $datos, 'huella' => Constancia::calcularHuella($datos), 'usuario_id' => $usuario->id,
                        'estado_id' => Estado::idDe(Estado::EMITIDO),
                    ]);
                    if ($modelo === ConstanciaRetiro::class) {
                        $constancia->salida_id = $turno->salida->id;
                    }
                    $constancia->save();

                    return $constancia;
                });
            } catch (QueryException $e) {
                if ($intento >= self::INTENTOS_NUMERO || ! in_array((string) $e->getCode(), self::CLAVE_REPETIDA, true)) {
                    throw $e;
                }
            }
        }
    }
}
