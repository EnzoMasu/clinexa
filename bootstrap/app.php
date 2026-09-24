<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\UsuarioActivo::class,
        ]);

        $middleware->alias([
            'permiso' => \App\Http\Middleware\VerificarPermiso::class,
        ]);

        // El permiso se verifica antes de buscar el registro de la URL: sin permiso es 403,
        // exista o no el registro (si no, un 404 revelaría qué IDs existen).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\VerificarPermiso::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
