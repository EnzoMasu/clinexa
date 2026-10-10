<?php

/*
 * El JavaScript del panel no guarda nada en el navegador (el contenido clínico vive solo en el formulario
 * y en el servidor) y los fragmentos HTML clínicos se insertan sin scripts ni atributos on*. La prueba de
 * que <script>, onerror y onload no se ejecutan se hizo en un navegador (ver el resumen de la etapa).
 */

/** El código de un archivo JS sin sus comentarios (para no contar las menciones en la documentación). */
function codigoJs(string $archivo): string
{
    $codigo = file_get_contents($archivo);

    return preg_replace(['#/\*.*?\*/#s', '#(?<![:\'"])//.*$#m'], '', $codigo);
}

test('ningún JS usa localStorage, sessionStorage, IndexedDB, cookies ni Cache Storage', function () {
    $archivos = glob(resource_path('js').'/*.js');
    expect($archivos)->not->toBeEmpty();

    foreach ($archivos as $archivo) {
        expect(codigoJs($archivo))->not->toMatch('/localStorage|sessionStorage|indexedDB|IDBFactory|document\.cookie|cookieStore|caches\./i', basename($archivo));
    }
});

test('las vistas tampoco tienen scripts en línea que usen almacenamiento del navegador', function () {
    $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
    foreach ($iterador as $archivo) {
        if (str_ends_with($archivo->getFilename(), '.blade.php')) {
            expect(file_get_contents($archivo->getPathname()))->not->toMatch('/localStorage|sessionStorage|indexedDB|document\.cookie/i', $archivo->getFilename());
        }
    }
});

test('la actualización periódica y el popup insertan el HTML con fragmentoInerte, nunca con innerHTML directo', function (string $archivo) {
    $codigo = codigoJs(resource_path("js/{$archivo}"));

    expect($codigo)->toContain("import fragmentoInerte from './fragmento-inerte'")
        ->toContain('fragmentoInerte(')
        ->not->toMatch('/\.innerHTML\s*=|insertAdjacentHTML|outerHTML\s*=|createContextualFragment|document\.write/');
})->with(['refresco-periodico.js', 'popup-consultas.js']);

test('fragmentoInerte quita los <script> y los atributos de evento nativos, y conserva los de Alpine', function () {
    $codigo = codigoJs(resource_path('js/fragmento-inerte.js'));

    expect($codigo)->toContain("createElement('template')")
        ->toContain("querySelectorAll('script')")
        ->toContain("startsWith('on')")
        ->toContain('removeAttribute');
});
