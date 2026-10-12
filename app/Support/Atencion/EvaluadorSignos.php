<?php

namespace App\Support\Atencion;

use App\Models\ParametroClinico;
use Illuminate\Support\Collection;

/**
 * El nivel de cada signo vital, en UN solo lugar, según los parámetros clínicos (ParametroClinico):
 *
 * - IMPOSIBLE: fuera de la banda "imposible" (rige siempre, aprobado o no), o negativo. En la presión, además,
 *   la sistólica tiene que ser mayor que la diastólica: REGLA NUEVA de esta etapa (antes el formulario solo
 *   validaba el formato 120/80), una sistólica menor o igual que la diastólica es imposible. Se rechaza (422).
 * - MUY_ALTERADO: fuera de la banda "muy alterado" (solo parámetros APROBADOS). Pide confirmación.
 * - FUERA_DE_RANGO: fuera de la banda "normal" (solo APROBADOS). Se guarda y se marca.
 * - NORMAL: dentro de lo normal, o sin parámetro aprobado ("aprobado" => false: no marca nada).
 *
 * Bordes incluidos: un valor igual al límite está dentro de la banda. La presión "120/80" se evalúa en sus dos
 * parámetros (PRESION_SISTOLICA y PRESION_DIASTOLICA) y queda con el peor nivel de los dos.
 */
final class EvaluadorSignos
{
    public const NORMAL = 'NORMAL';

    public const FUERA_DE_RANGO = 'FUERA_DE_RANGO';

    public const MUY_ALTERADO = 'MUY_ALTERADO';

    public const IMPOSIBLE = 'IMPOSIBLE';

    private const GRAVEDAD = [self::NORMAL => 0, self::FUERA_DE_RANGO => 1, self::MUY_ALTERADO => 2, self::IMPOSIBLE => 3];

    /** Etiqueta visible de cada nivel marcado (con texto, no solo color). */
    public const ETIQUETAS = [self::FUERA_DE_RANGO => 'Fuera de lo normal', self::MUY_ALTERADO => 'Muy alterado'];

    /** @var Collection<string, ParametroClinico> por código */
    private Collection $parametros;

    public function __construct(?Collection $parametros = null)
    {
        $this->parametros = ($parametros ?? ParametroClinico::with('estado')->get())->keyBy('codigo');
    }

    /** Si falta aprobar algún parámetro (el banner: "el sistema no marca alertas"). */
    public static function faltanAprobados(): bool
    {
        return ParametroClinico::where('estado_id', '!=', \App\Models\Estado::idDe(\App\Models\Estado::APROBADO))->exists()
            || ParametroClinico::doesntExist();
    }

    /**
     * Evalúa los signos cargados (campo => valor, como los guarda el formulario: "120/80", "38.2", 88). Devuelve
     * por campo: nivel, dirección ('alto'|'bajo'|null), si su parámetro está aprobado y, si es imposible, el mensaje.
     *
     * @return array<string, array{nivel: string, direccion: ?string, aprobado: bool, mensaje: ?string}>
     */
    public function evaluar(array $examen): array
    {
        $resultado = [];
        foreach (FormularioConsulta::SIGNOS_VITALES as $campo) {
            $valor = $examen[$campo] ?? null;
            if ($valor === null || $valor === '') {
                continue;
            }
            $resultado[$campo] = $campo === 'presion_arterial' ? $this->presion((string) $valor) : $this->numero($campo, $valor);
        }

        return $resultado;
    }

    /** Un signo numérico contra el parámetro de su campo. */
    private function numero(string $campo, mixed $valor): array
    {
        $parametro = $this->parametros->first(fn (ParametroClinico $p) => $p->campo === $campo);
        $nombre = $parametro?->nombre ?? $campo;
        if (! is_numeric($valor)) {
            return $this->imposible($nombre, null);
        }

        return $this->nivel((float) $valor, $parametro, $nombre);
    }

    /** La presión "120/80": la sistólica y la diastólica, cada una con su parámetro; el peor nivel de las dos. */
    private function presion(string $valor): array
    {
        if (! preg_match('#^(\d{1,3})/(\d{1,3})$#', $valor, $partes)) {
            return $this->imposible('Presión arterial', null);
        }
        [$sistolica, $diastolica] = [(float) $partes[1], (float) $partes[2]];
        if ($sistolica <= $diastolica || $sistolica <= 0 || $diastolica <= 0) {
            return ['nivel' => self::IMPOSIBLE, 'direccion' => null, 'aprobado' => true,
                'mensaje' => 'El valor de PRESIÓN ARTERIAL no es posible: la sistólica tiene que ser mayor que la diastólica y las dos, positivas.'];
        }

        $niveles = [
            $this->nivel($sistolica, $this->parametros->get('PRESION_SISTOLICA'), 'Presión sistólica'),
            $this->nivel($diastolica, $this->parametros->get('PRESION_DIASTOLICA'), 'Presión diastólica'),
        ];
        usort($niveles, fn ($a, $b) => self::GRAVEDAD[$b['nivel']] <=> self::GRAVEDAD[$a['nivel']]);

        return [...$niveles[0], 'aprobado' => $niveles[0]['aprobado'] || $niveles[1]['aprobado']];
    }

    /** Las tres bandas, de afuera hacia adentro. */
    private function nivel(float $valor, ?ParametroClinico $parametro, string $nombre): array
    {
        $direccion = fn (?float $minimo, ?float $maximo) => match (true) {
            $minimo !== null && $valor < $minimo => 'bajo',
            $maximo !== null && $valor > $maximo => 'alto',
            default => null,
        };

        if ($valor < 0 || ($parametro && ($d = $direccion($parametro->imposible_min, $parametro->imposible_max)) !== null)) {
            return $this->imposible($nombre, $d ?? 'bajo');
        }
        if (! $parametro?->aprobado()) {
            return ['nivel' => self::NORMAL, 'direccion' => null, 'aprobado' => false, 'mensaje' => null];
        }
        if (($d = $direccion($parametro->muy_alterado_min, $parametro->muy_alterado_max)) !== null) {
            return ['nivel' => self::MUY_ALTERADO, 'direccion' => $d, 'aprobado' => true, 'mensaje' => null];
        }
        if (($d = $direccion($parametro->normal_min, $parametro->normal_max)) !== null) {
            return ['nivel' => self::FUERA_DE_RANGO, 'direccion' => $d, 'aprobado' => true, 'mensaje' => null];
        }

        return ['nivel' => self::NORMAL, 'direccion' => null, 'aprobado' => true, 'mensaje' => null];
    }

    private function imposible(string $nombre, ?string $direccion): array
    {
        return ['nivel' => self::IMPOSIBLE, 'direccion' => $direccion, 'aprobado' => true,
            'mensaje' => 'El valor de '.mb_strtoupper($nombre).' no es posible.'];
    }
}
