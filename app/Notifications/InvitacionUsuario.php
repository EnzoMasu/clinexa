<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email para un usuario recién creado por un administrador. Usa el mismo token y el mismo
 * link que "restablecer contraseña" (ResetPassword), pero con un texto de bienvenida.
 */
class InvitacionUsuario extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $minutos = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Bienvenido/a a Clinexa - Definí tu contraseña')
            ->greeting('¡Hola!')
            ->line('Se te creó un usuario en el sistema Clinexa.')
            ->line('Para empezar a usarlo, definí tu contraseña con el siguiente botón:')
            ->action('Definir mi contraseña', $url)
            ->line("Este link vence en {$minutos} minutos. Si vence, pedile al administrador que te reenvíe la invitación.")
            ->line('Si no esperabas este correo, podés ignorarlo.');
    }
}
