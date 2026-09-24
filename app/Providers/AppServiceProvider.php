<?php

namespace App\Providers;

use App\Rules\SinCaracteresRepetidos;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Complejidad de contraseña. Breeze usa Password::defaults() al definirla (reset inicial,
        // "olvidé mi contraseña") y al cambiarla desde el perfil, así que aplica en todos lados.
        // Si se cambia, actualizar el texto de ayuda en components/password-requirements.
        Password::defaults(fn () => Password::min(8)
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->rules([new SinCaracteresRepetidos]));
    }
}
