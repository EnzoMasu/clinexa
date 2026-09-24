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
    /**
     * El saludo se arma acá porque buildMailMessage() solo recibe la URL, no el destinatario.
     */
    public function toMail($notifiable): MailMessage
    {
        $nombre = trim((string) $notifiable->name);

        return parent::toMail($notifiable)
            ->greeting($nombre !== '' ? "Estimado/a {$nombre}:" : 'Estimado/a:');
    }

    protected function buildMailMessage($url): MailMessage
    {
        $minutos = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Bienvenido/a a Clinexa - Defina su contraseña')
            ->line('Se le creó un usuario en el sistema Clinexa.')
            ->line('Para comenzar a usarlo, defina su contraseña con el siguiente botón:')
            ->action('Definir mi contraseña', $url)
            ->line("Este link vence en {$minutos} minutos. Si vence, solicite al administrador que le reenvíe la invitación.")
            ->line('Si no esperaba este correo, puede ignorarlo.');
    }
}
