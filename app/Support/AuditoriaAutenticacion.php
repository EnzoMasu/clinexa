<?php

namespace App\Support;

use App\Enums\AccionAuditoria;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Registra en la auditoría los eventos de autenticación de Laravel. Nunca se guarda la contraseña
 * intentada ni ningún valor de contraseña: solo el correo y el motivo.
 *
 * (Suscriptor registrado en AppServiceProvider; está fuera de app/Listeners para que el
 * descubrimiento automático de Laravel no lo registre dos veces.)
 */
class AuditoriaAutenticacion
{
    public function subscribe(Dispatcher $eventos): void
    {
        $eventos->listen(Login::class, [self::class, 'inicioDeSesion']);
        $eventos->listen(Failed::class, [self::class, 'intentoFallido']);
        $eventos->listen(Logout::class, [self::class, 'cierreDeSesion']);
        $eventos->listen(Lockout::class, [self::class, 'bloqueoTemporal']);
        $eventos->listen(PasswordReset::class, [self::class, 'contrasenaRestablecida']);
    }

    public function inicioDeSesion(Login $evento): void
    {
        if (Auditoria::enPedidoWeb()) {
            Auditoria::registrar(AccionAuditoria::INICIO_SESION, 'users', $evento->user->getAuthIdentifier(), usuarioId: $evento->user->getAuthIdentifier());
        }
    }

    /** Contraseña incorrecta (con usuario) o correo inexistente (sin usuario). */
    public function intentoFallido(Failed $evento): void
    {
        if (! Auditoria::enPedidoWeb()) {
            return;
        }

        $correo = $evento->credentials['email'] ?? '';
        $id = $evento->user?->getAuthIdentifier();

        Auditoria::registrar(AccionAuditoria::INICIO_SESION_FALLIDO, 'users', $id,
            detalle: "Correo: {$correo}. ".($evento->user ? 'Contraseña incorrecta.' : 'El correo no corresponde a ningún usuario.'),
            usuarioId: $id);
    }

    public function cierreDeSesion(Logout $evento): void
    {
        if (! Auditoria::enPedidoWeb() || ! $evento->user) {
            return;
        }

        // Si la cerró el sistema (cuenta bloqueada o desactivada durante la sesión), con el motivo.
        $motivo = app(ContextoAuditoria::class)->motivoCierre;
        Auditoria::registrar(AccionAuditoria::CIERRE_SESION, 'users', $evento->user->getAuthIdentifier(),
            detalle: $motivo ? "Sesión cerrada por el sistema: {$motivo}" : null,
            usuarioId: $evento->user->getAuthIdentifier());
    }

    /**
     * Demasiados intentos fallidos: el inicio de sesión queda bloqueado unos segundos para ese correo
     * e IP. Se registra una vez por bloqueo (no en cada intento mientras dure).
     */
    public function bloqueoTemporal(Lockout $evento): void
    {
        if (! Auditoria::enPedidoWeb()) {
            return;
        }

        $correo = (string) $evento->request->input('email');
        $segundos = $evento->request instanceof LoginRequest ? RateLimiter::availableIn($evento->request->throttleKey()) : 60;
        $usuario = User::where('email', mb_strtolower($correo))->first();
        $detalle = "Bloqueo temporal por demasiados intentos fallidos. Correo: {$correo}.";

        $yaRegistrado = DB::table('logs_auditoria')
            ->where('accion', AccionAuditoria::BLOQUEO->value)
            ->where('detalle', 'like', $detalle.'%')
            ->where('ip_origen', $evento->request->ip())
            ->where('fecha_hora', '>=', now()->subSeconds(max($segundos, 60)))
            ->exists();

        if (! $yaRegistrado) {
            Auditoria::registrar(AccionAuditoria::BLOQUEO, 'users', $usuario?->id,
                detalle: "{$detalle} Durante {$segundos} segundos.", usuarioId: $usuario?->id);
        }
    }

    /**
     * Contraseña definida con el link del correo: si el usuario nunca entró es la invitación; si ya
     * entró alguna vez, un restablecimiento. (El cambio desde el perfil se registra en PasswordController.)
     */
    public function contrasenaRestablecida(PasswordReset $evento): void
    {
        if (! Auditoria::enPedidoWeb()) {
            return;
        }

        $origen = $evento->user->ultimo_acceso === null ? 'invitación (primera contraseña)' : 'restablecimiento por link';
        Auditoria::registrar(AccionAuditoria::CAMBIO_CONTRASENA, 'users', $evento->user->getAuthIdentifier(),
            detalle: "Origen: {$origen}.", usuarioId: $evento->user->getAuthIdentifier());
    }
}
