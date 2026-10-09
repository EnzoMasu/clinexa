<?php

namespace App\Support;

/**
 * Estimación conservadora de si una receta entra en su hoja: A4 vertical dividida en dos mitades de
 * 148,5 mm (arriba la receta, abajo las indicaciones), con 10 mm de relleno. Es el único lugar con
 * estos números. La hoja (recetas/_hoja) usa la misma tipografía que se supone acá: 10 pt con
 * interlineado 1,35, es decir, ~4,8 mm por línea.
 *
 * - Alto útil de cada mitad: 148,5 − 2 × 10 = 128,5 mm → 26 líneas (con margen: 26 × 4,8 = 125 mm).
 * - Ancho útil: 210 − 2 × 10 = 190 mm. A 10 pt, un texto común promedia ~1,9 mm por carácter (~100
 *   por línea); se cuentan 80 para cubrir palabras largas, mayúsculas y el corte por palabra.
 * - Lo fijo de cada mitad (encabezado, paciente, títulos, firma) se cuenta aparte.
 * - Las mayúsculas y los dígitos cuentan 1,3 caracteres y las letras anchas 2 (ver ancho()): un texto
 *   en mayúsculas ocupa más, y uno sin espacios se parte en cualquier punto (overflow-wrap en la hoja).
 *
 * Si la hoja real se pasara igual (texto raro, fuente distinta en el equipo), la pantalla de impresión
 * lo detecta midiendo y muestra un aviso: nunca se corta contenido en silencio.
 */
final class MediaHoja
{
    /** Caracteres por línea que se cuentan (conservador: entran más), medidos en minúsculas. */
    public const ANCHO_LINEA = 80;

    /** Cuánto cuenta una mayúscula o un dígito, en minúsculas. */
    public const PESO_MAYUSCULA = 1.3;

    /** Cuánto cuentan las letras más anchas (W, M, @, %). */
    public const PESO_ANCHA = 2.0;

    /** Líneas de texto que entran en el alto útil de una mitad. */
    public const LINEAS_POR_MITAD = 26;

    /** Mitad de arriba: logo y datos de la clínica (3), título y fecha (1), paciente (2), espacios (2), firma (3). */
    public const LINEAS_FIJAS_RECETA = 11;

    /** Mitad de abajo: título (1), paciente, fecha y número (2), "Cómo tomar su medicación" (1), espacios (1), profesional (2). */
    public const LINEAS_FIJAS_INDICACIONES = 7;

    public const EXCEDE = 'El contenido no entra en media hoja. Acorte los textos o divida la receta en dos.';

    /**
     * Líneas que ocupa cada mitad con estos datos.
     *
     * @param  list<array<string, ?string>>  $medicamentos  renglones (medicamento, cantidad, dosis, via, frecuencia, duracion, observaciones)
     * @param  list<array{tipo: ?string, texto: string}>  $indicaciones  indicaciones generales activas
     * @return array{receta: int, indicaciones: int}
     */
    public static function lineas(array $medicamentos, ?string $observaciones, array $indicaciones, bool $conReemplazo = false): array
    {
        $receta = self::LINEAS_FIJAS_RECETA + ($conReemplazo ? 1 : 0);
        foreach ($medicamentos as $medicamento) {
            $receta += self::alto(($medicamento['medicamento'] ?? '').(filled($medicamento['cantidad'] ?? null) ? ' — Cantidad: '.$medicamento['cantidad'] : ''));
        }
        if (filled($observaciones)) {
            $receta += self::alto('Observaciones: '.$observaciones);
        }

        $abajo = self::LINEAS_FIJAS_INDICACIONES;
        foreach ($medicamentos as $medicamento) {
            $abajo += self::alto((string) ($medicamento['medicamento'] ?? ''))
                + self::alto(self::renglonDeToma($medicamento))
                + (filled($medicamento['observaciones'] ?? null) ? self::alto('Observaciones: '.$medicamento['observaciones']) : 0);
        }
        if ($indicaciones !== []) {
            $abajo += 1; // título "Indicaciones generales"
            foreach ($indicaciones as $indicacion) {
                $abajo += self::alto(($indicacion['tipo'] ? $indicacion['tipo'].': ' : '').$indicacion['texto']);
            }
        }

        return ['receta' => $receta, 'indicaciones' => $abajo];
    }

    /** Si alguna de las dos mitades se pasa. */
    public static function excede(array $medicamentos, ?string $observaciones, array $indicaciones, bool $conReemplazo = false): bool
    {
        return max(self::lineas($medicamentos, $observaciones, $indicaciones, $conReemplazo)) > self::LINEAS_POR_MITAD;
    }

    /** "Dosis: … · Vía: … · Frecuencia: … · Duración: …" (lo que se imprime en una línea rotulada). */
    public static function renglonDeToma(array $medicamento): string
    {
        return collect(['dosis' => 'Dosis', 'via' => 'Vía', 'frecuencia' => 'Frecuencia', 'duracion' => 'Duración'])
            ->filter(fn (string $etiqueta, string $campo) => filled($medicamento[$campo] ?? null))
            ->map(fn (string $etiqueta, string $campo) => "{$etiqueta}: {$medicamento[$campo]}")
            ->join(' · ');
    }

    /** Líneas que ocupa un texto (al menos una). Los saltos de línea cuentan. */
    public static function alto(string $texto): int
    {
        return collect(preg_split('/\R/', $texto))->sum(fn (string $linea) => max(1, (int) ceil(self::ancho($linea) / self::ANCHO_LINEA)));
    }

    /**
     * Ancho de un texto en "caracteres de minúscula": una mayúscula o un dígito ocupa ~35 % más que una
     * minúscula, y las letras anchas (W, M) el doble. Medido en la hoja: un nombre de medicamento en
     * mayúsculas contado como minúsculas subestimaba cuatro líneas de la mitad de abajo.
     */
    public static function ancho(string $texto): float
    {
        $anchas = preg_match_all('/[WMwm@%]/u', $texto);
        $mayusculas = preg_match_all('/[\p{Lu}\d]/u', $texto) - preg_match_all('/[WM]/u', $texto);

        return mb_strlen($texto) + $mayusculas * (self::PESO_MAYUSCULA - 1) + $anchas * (self::PESO_ANCHA - 1);
    }
}
