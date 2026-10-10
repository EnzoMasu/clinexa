<?php

/*
 * Contraste en modo oscuro. Un dato en monoespaciado (hora, código, documento) sin color de texto propio
 * hereda el color de afuera; fuera de una tabla eso era el negro del navegador, casi invisible sobre el
 * fondo oscuro. Regla: todo elemento con font-mono lleva un color para modo oscuro (dark:text-...) en su
 * misma clase, o usa la clase compartida "dato" (resources/css/app.css: font-mono + el par de colores).
 *
 * Para evitar falsos positivos:
 * - Se mira la etiqueta completa de cada elemento (respetando comillas), no líneas sueltas: una clase
 *   partida en varias líneas o un "->" dentro de un atributo no confunden al lector.
 * - Los componentes Blade (<x-...>) cuentan como coloreados si su archivo (o el componente que usa
 *   adentro) ya trae dark:text-; p. ej. <x-admin.input class="font-mono"> usa x-text-input, que lo trae.
 * - La hoja de la receta (recetas/hoja.blade.php) se excluye: se imprime en papel blanco y no tiene modo
 *   oscuro.
 * Y, para no tener falsos negativos, el lector se prueba con ejemplos buenos y malos.
 */

const VISTAS_SIN_MODO_OSCURO = ['recetas/hoja.blade.php'];

/** Las etiquetas de apertura de un HTML/Blade: [nombre, texto completo de la etiqueta]. */
function etiquetasDe(string $html): array
{
    $etiquetas = [];
    $largo = strlen($html);
    for ($i = 0; $i < $largo; $i++) {
        if ($html[$i] !== '<' || ! preg_match('/\G<([a-zA-Z][\w.:-]*)/', $html, $m, 0, $i)) {
            continue;
        }
        // Hasta el ">" que cierra la etiqueta, salteando lo que está entre comillas.
        $comilla = null;
        for ($j = $i + strlen($m[0]); $j < $largo; $j++) {
            $c = $html[$j];
            if ($comilla !== null) {
                $comilla = $c === $comilla ? null : $comilla;
            } elseif ($c === '"' || $c === "'") {
                $comilla = $c;
            } elseif ($c === '>') {
                break;
            }
        }
        $etiquetas[] = [$m[1], substr($html, $i, $j - $i + 1)];
        $i = $j;
    }

    return $etiquetas;
}

/** Las clases estáticas de una etiqueta (atributo class="..."). */
function clasesDe(string $etiqueta): string
{
    return preg_match('/\sclass="([^"]*)"/', $etiqueta, $m) ? $m[1] : '';
}

/** El componente Blade (x-admin.input) trae color para modo oscuro, él o el componente que usa adentro. */
function componenteConColor(string $nombre, int $nivel = 0): bool
{
    $archivo = resource_path('views/components/'.str_replace('.', '/', substr($nombre, 2)).'.blade.php');
    if (! is_file($archivo) || $nivel > 2) {
        return false;
    }
    $contenido = file_get_contents($archivo);
    if (str_contains($contenido, 'dark:text-')) {
        return true;
    }
    preg_match_all('/<(x-[\w.-]+)/', $contenido, $usados);

    return collect($usados[1])->contains(fn (string $usado) => componenteConColor($usado, $nivel + 1));
}

/** Los elementos con font-mono sin color para modo oscuro de un HTML: los textos de sus etiquetas. */
function monoSinColorOscuro(string $html): array
{
    return collect(etiquetasDe($html))
        ->filter(fn (array $e) => preg_match('/(^|\s)font-mono(\s|$)/', clasesDe($e[1])))
        ->reject(fn (array $e) => str_contains(clasesDe($e[1]), 'dark:text-'))
        ->reject(fn (array $e) => str_starts_with($e[0], 'x-') && componenteConColor($e[0]))
        ->map(fn (array $e) => trim(preg_replace('/\s+/', ' ', $e[1])))
        ->values()->all();
}

test('el lector detecta los casos malos y deja pasar los buenos (sin falsos positivos ni negativos)', function () {
    $malo = <<<'HTML'
        <span class="w-24 font-mono text-xs">{{ $hora }}</span>
        <td class="px-6 font-mono
            text-sm">{{ $x->y }}</td>
        <span class="font-mono text-gray-900">sin modo oscuro</span>
        HTML;
    $bueno = <<<'HTML'
        <span class="dato text-xs">{{ $hora }}</span>
        <span class="font-mono text-gray-600 dark:text-gray-400" x-text="a > b ? 'x' : 'y'">secundario</span>
        <x-admin.input name="codigo" :value="$pais->codigo" class="uppercase font-mono" />
        <p class="text-sm">sin monoespaciado</p>
        HTML;

    expect(monoSinColorOscuro($malo))->toHaveCount(3)
        ->and(monoSinColorOscuro($bueno))->toBe([]);
});

test('ninguna vista tiene un elemento font-mono sin color para modo oscuro', function () {
    $raiz = resource_path('views');
    $problemas = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz)) as $archivo) {
        $relativo = str_replace('\\', '/', substr($archivo->getPathname(), strlen($raiz) + 1));
        if (! str_ends_with($relativo, '.blade.php') || in_array($relativo, VISTAS_SIN_MODO_OSCURO, true)) {
            continue;
        }
        foreach (monoSinColorOscuro(file_get_contents($archivo->getPathname())) as $etiqueta) {
            $problemas[] = "{$relativo}: {$etiqueta}";
        }
    }

    expect($problemas)->toBe([], "Use la clase \"dato\" o agregue dark:text-...:\n".implode("\n", $problemas));
});

test('la clase compartida "dato" existe con el par de colores, y los layouts tienen color de texto para modo oscuro', function () {
    expect(file_get_contents(resource_path('css/app.css')))->toMatch('/\.dato\s*\{\s*@apply font-mono text-gray-900 dark:text-gray-100;/');

    foreach (['layouts/app.blade.php', 'layouts/guest.blade.php'] as $layout) {
        preg_match('/<body class="([^"]*)"/', file_get_contents(resource_path("views/{$layout}")), $body);
        expect($body[1] ?? '')->toContain('text-gray-900')->toContain('dark:text-gray-100');
    }
});

test('las horas de las listas de Consulta, Preparación, Turnos y Disponibilidades usan la clase compartida', function () {
    foreach ([
        'admin/atencion/_tabla.blade.php' => 'substr($turno->hora_inicio, 0, 5)',
        'admin/preparacion/_tabla.blade.php' => 'substr($turno->hora_inicio, 0, 5)',
        'admin/turnos/_tabla.blade.php' => 'substr($turno->hora_inicio, 0, 5)',
        'admin/disponibilidades/_tabla.blade.php' => 'Disponibilidad::hora(',
        'admin/atencion/cerrar-jornada.blade.php' => 'substr($turno->hora_inicio, 0, 5)',
    ] as $vista => $hora) {
        $html = file_get_contents(resource_path("views/{$vista}"));
        expect($html)->toMatch('/class="[^"]*\bdato\b[^"]*">[^<]*'.preg_quote($hora, '/').'/');
    }
});
