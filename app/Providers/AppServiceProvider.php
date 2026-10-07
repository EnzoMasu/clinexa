<?php

namespace App\Providers;

use App\Rules\SinCaracteresRepetidos;
use App\Support\AuditoriaAutenticacion;
use App\Support\ContextoAuditoria;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Estado de la auditoría, uno por pedido (lo enciende el middleware ContextoAuditoriaWeb).
        $this->app->scoped(ContextoAuditoria::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Auditoría de inicio y cierre de sesión, intentos fallidos, bloqueos y cambios de contraseña.
        Event::subscribe(AuditoriaAutenticacion::class);

        // Nombre del campo estado_id en los mensajes de validación ("El campo estado es obligatorio").
        // Va acá y no en lang/es/validation.php porque lang:update pisa ese archivo. Primero se carga
        // el archivo completo: addLines sobre un grupo sin cargar lo dejaría solo con esta línea.
        Lang::load('*', 'validation', 'es');
        Lang::addLines(['validation.attributes.estado_id' => 'estado'], 'es');

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
