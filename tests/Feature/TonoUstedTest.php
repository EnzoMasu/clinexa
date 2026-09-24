<?php

/**
 * La aplicación trata al usuario de "usted". Esto evita que vuelvan a colarse formas de voseo
 * o tuteo en los textos que se ven en pantalla o en los emails.
 */
test('los textos de la aplicación usan "usted", no voseo ni tuteo', function () {
    $formasInformales = '/(?<![\p{L}])(podés|tenés|querés|necesitás|sabés|pedile|pedíselo|contactá|definí|ejecutá|revisá|ingresá|elegí|esperabas|puedes|tienes|quieres|necesitas|olvidaste|tu|tus|te|vos)(?![\p{L}])/iu';

    $archivos = [
        ...glob(base_path('app/{Http/Controllers,Http/Controllers/Admin,Http/Middleware,Http/Requests/Admin,Models,Notifications,Rules}/*.php'), GLOB_BRACE),
        ...new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)),
    ];

    $encontrados = [];
    foreach ($archivos as $archivo) {
        foreach (file((string) $archivo) as $numero => $linea) {
            // Se revisan solo los textos, no los comentarios de código.
            if (preg_match('#^\s*(//|\*|/\*|\{\{--)#', $linea)) {
                continue;
            }
            if (preg_match($formasInformales, $linea, $m)) {
                $encontrados[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) $archivo).':'.($numero + 1)." → \"{$m[1]}\"";
            }
        }
    }

    expect($encontrados)->toBeEmpty();
});
