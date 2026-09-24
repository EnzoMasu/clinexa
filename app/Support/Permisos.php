<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Consulta los permisos a partir del middleware "permiso:MODULO,ACCION" declarado en cada ruta,
 * así el menú y los botones de las vistas usan la misma definición que protege las rutas.
 */
class Permisos
{
    /**
     * Si el usuario logueado puede usar la ruta con ese nombre.
     */
    public static function puedeRuta(string $nombre): bool
    {
        $ruta = Route::getRoutes()->getByName($nombre);

        if (! $ruta || ! Auth::check()) {
            return false;
        }

        foreach ($ruta->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permiso:')) {
                [$modulo, $accion] = explode(',', substr($middleware, strlen('permiso:')));

                return Auth::user()->tienePermiso($modulo, $accion);
            }
        }

        return true;
    }

    /**
     * URL de la ruta si el usuario puede usarla, o null si no (para ocultar links y botones).
     */
    public static function url(string $nombre, mixed $parametros = []): ?string
    {
        return self::puedeRuta($nombre) ? route($nombre, $parametros) : null;
    }
}
