<?php

namespace App\Support\Reposo;

use App\Exceptions\AccionRechazada;
use App\Models\Constancia;
use App\Models\Consulta;
use App\Models\Estado;
use App\Models\ReposoMedico;
use App\Models\User;
use App\Support\Fecha;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emite un certificado de reposo de una consulta EN_CURSO o FINALIZADA, con la consulta bloqueada. Asigna número
 * correlativo (RM-0000001), fecha de hoy en hora de Paraguay, snapshot y huella sha256. La autorización (la policy) la hace
 * el llamador; acá se revalida lo que depende de la base: el estado de la consulta, que los diagnósticos elegidos sean
 * vigentes y de esta consulta, que "desde" no sea anterior a hoy y la prórroga (anterior EMITIDO, del mismo paciente, y
 * "desde" posterior a su "hasta").
 */
final class EmitirReposo
{
    /** SQLSTATE de clave única repetida (PostgreSQL 23505; SQLite informa 23000). */
    private const CLAVE_REPETIDA = ['23505', '23000'];

    private const INTENTOS_NUMERO = 5;

    /**
     * @param  array{dias: int, desde: \Illuminate\Support\Carbon (en hora local), modalidad: string, modalidad_otra: ?string, empleador: ?string, observaciones: ?string, diagnosticos: list<int>, prorroga_de: ?string}  $datos
     */
    public static function ejecutar(User $usuario, Consulta $consulta, array $datos): ReposoMedico
    {
        return DB::transaction(function () use ($usuario, $consulta, $datos) {
            $consulta = Consulta::with('estado')->whereKey($consulta->id)->lockForUpdate()->firstOrFail();
            if (! $consulta->enCurso() && ! $consulta->finalizada()) {
                throw new AccionRechazada('El reposo se emite solo de una consulta en curso o finalizada.');
            }
            if ($datos['desde']->format('Y-m-d') < Fecha::hoy()->format('Y-m-d')) {
                throw ValidationException::withMessages(['desde' => 'La fecha "desde" no puede ser anterior a hoy.']);
            }

            $elegidos = array_values(array_unique(array_map('intval', $datos['diagnosticos'] ?? [])));
            $diagnosticos = $consulta->diagnosticos()->with('cie10')->where('activo', true)->whereIn('id', $elegidos)->get();
            if ($diagnosticos->count() !== count($elegidos)) {
                throw ValidationException::withMessages(['diagnosticos' => 'Elija solo diagnósticos vigentes de esta consulta.']);
            }

            $anterior = self::prorroga($consulta, $datos);
            $snapshot = DatosReposo::armar($consulta, $usuario, $datos, $diagnosticos, $anterior);

            return self::guardar($consulta, $usuario, $anterior, $snapshot);
        });
    }

    /** El certificado que este prorroga, verificado (bloqueado), o null si no es una prórroga. */
    private static function prorroga(Consulta $consulta, array $datos): ?ReposoMedico
    {
        $numero = strtoupper(trim((string) ($datos['prorroga_de'] ?? '')));
        if ($numero === '') {
            return null;
        }

        $anterior = ReposoMedico::with(['estado', 'consulta'])->where('numero', $numero)->lockForUpdate()->first();
        $error = match (true) {
            $anterior === null => 'No existe un certificado de reposo con ese número.',
            (int) $anterior->consulta->historia_clinica_id !== (int) $consulta->historia_clinica_id => 'El certificado anterior es de otro paciente.',
            ! $anterior->estaEmitido() => 'El certificado anterior está anulado: no se puede prorrogar.',
            $datos['desde']->format('Y-m-d') <= $anterior->hasta->format('Y-m-d') => 'La prórroga tiene que empezar después del último día del certificado anterior ('.Fecha::mostrar($anterior->hasta).').',
            default => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['prorroga_de' => $error]);
        }

        return $anterior;
    }

    /** Si otra emisión simultánea tomó el mismo número, el unique de la base lo rechaza y se reintenta con el siguiente. */
    private static function guardar(Consulta $consulta, User $usuario, ?ReposoMedico $anterior, array $snapshot): ReposoMedico
    {
        for ($intento = 1; ; $intento++) {
            $numero = ReposoMedico::siguienteNumero();
            $datos = [...$snapshot, 'numero' => $numero];
            try {
                return DB::transaction(fn () => ReposoMedico::create([
                    'numero' => $numero, 'consulta_id' => $consulta->id, 'prorroga_de_id' => $anterior?->id,
                    'fecha' => $datos['fecha'], 'dias' => $datos['dias'], 'desde' => $datos['desde'], 'hasta' => $datos['hasta'],
                    'emitida_en' => now(), 'snapshot' => $datos, 'huella' => Constancia::calcularHuella($datos),
                    'usuario_id' => $usuario->id, 'estado_id' => Estado::idDe(Estado::EMITIDO),
                ]));
            } catch (QueryException $e) {
                if ($intento >= self::INTENTOS_NUMERO || ! in_array((string) $e->getCode(), self::CLAVE_REPETIDA, true)) {
                    throw $e;
                }
            }
        }
    }
}
