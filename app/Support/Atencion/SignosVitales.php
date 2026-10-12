<?php

namespace App\Support\Atencion;

use App\Models\Consulta;
use App\Models\ExamenFisico;

/**
 * Confirmación de los signos MUY ALTERADOS (EvaluadorSignos), antes de guardar:
 *
 * - Un valor MUY_ALTERADO se guarda solo con su confirmación EXACTA: confirmaciones[campo] = el mismo valor (o
 *   si ya se había confirmado ese mismo valor antes, guardado en signos_alertas). Sin ella, ese campo NO se guarda
 *   (queda el valor anterior) y se informa como "pendiente de confirmar"; los demás campos sí se guardan.
 * - Los IMPOSIBLES ya los rechazó FormularioConsulta (422). FUERA_DE_RANGO se guarda y se marca.
 * - Lo confirmado viaja a GuardarSecciones (datos['confirmaciones_signos']) para guardar quién y cuándo y que la
 *   auditoría lo muestre ("Confirmó valor muy alto: Temperatura 41,5 °C").
 */
final class SignosVitales
{
    public const REQUIERE_CONFIRMACION = 'requiere_confirmacion';

    public const MENSAJE = 'Hay valores muy alterados sin confirmar: no se guardaron. Confírmelos o corríjalos.';

    /**
     * Revisa los signos de $datos (ya validados) y los ajusta: los muy alterados sin confirmar vuelven al valor
     * guardado. Devuelve los pendientes: campo => [valor, texto, mensaje].
     *
     * @return array<string, array{valor: string, texto: string, mensaje: string}>
     */
    public static function revisar(Consulta $consulta, array &$datos, mixed $confirmaciones): array
    {
        if (! array_key_exists('examen', $datos)) {
            return [];
        }
        $confirmaciones = is_array($confirmaciones) ? $confirmaciones : [];
        $existente = $consulta->examenFisico()->first();
        $previas = $existente?->signos_alertas ?? [];
        $evaluacion = (new EvaluadorSignos)->evaluar($datos['examen']);
        $pendientes = [];
        $confirmadas = [];

        foreach ($evaluacion as $campo => $resultado) {
            if ($resultado['nivel'] !== EvaluadorSignos::MUY_ALTERADO) {
                continue;
            }
            $valor = self::normal($datos['examen'][$campo]);
            $confirmadoAhora = array_key_exists($campo, $confirmaciones) && self::normal($confirmaciones[$campo]) === $valor;
            $confirmadoAntes = ($previas[$campo]['confirmado_valor'] ?? null) === $valor;
            if ($confirmadoAhora || $confirmadoAntes) {
                if ($confirmadoAhora && ! $confirmadoAntes) {
                    $confirmadas[$campo] = $valor;
                }

                continue;
            }

            $texto = (new ExamenFisico([$campo => $datos['examen'][$campo]]))->valorTexto($campo) ?? $valor;
            $pendientes[$campo] = [
                'valor' => $valor,
                'texto' => $texto,
                'mensaje' => 'Valor muy '.($resultado['direccion'] === 'bajo' ? 'bajo' : 'alto').": {$texto}. ¿Está seguro de que es correcto?",
            ];
            // No se guarda: queda el valor anterior.
            $datos['examen'][$campo] = $existente?->getRawOriginal($campo);
        }
        $datos['confirmaciones_signos'] = $confirmadas;

        return $pendientes;
    }

    /** "41,5", "41.5" y "41.50" son el mismo valor; la presión ("120/80") queda como texto. */
    public static function normal(mixed $valor): string
    {
        $texto = str_replace(',', '.', trim((string) $valor));

        return is_numeric($texto) ? (string) (float) $texto : $texto;
    }
}
