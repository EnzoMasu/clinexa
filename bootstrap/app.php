<?php

use App\Http\Middleware\ContextoAuditoriaWeb;
use App\Http\Middleware\UsuarioActivo;
use App\Http\Middleware\VerificarAlgunPermiso;
use App\Http\Middleware\VerificarPermiso;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            UsuarioActivo::class,
        ]);

        // Auditoría: marca el pedido web (solo entonces se registran cambios). Va primero, antes
        // de UsuarioActivo, que puede cerrar la sesión (y eso también se registra).
        $middleware->web(prepend: [
            ContextoAuditoriaWeb::class,
        ]);

        $middleware->alias([
            'permiso' => VerificarPermiso::class,
            'permiso.alguno' => VerificarAlgunPermiso::class,
        ]);

        // El permiso se verifica antes de buscar el registro de la URL: sin permiso es 403,
        // exista o no el registro (si no, un 404 revelaría qué IDs existen).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: VerificarPermiso::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: VerificarAlgunPermiso::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
