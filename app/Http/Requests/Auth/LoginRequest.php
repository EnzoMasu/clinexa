<?php

namespace App\Http\Requests\Auth;

use App\Enums\AccionAuditoria;
use App\Support\Auditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Primero se valida la contraseña y que la cuenta pueda entrar; recién entonces se inicia la
        // sesión. (Con Auth::attempt la sesión se abría y se cerraba enseguida si la cuenta estaba
        // bloqueada: la auditoría habría registrado un inicio y un cierre de sesión que no existieron.)
        $credenciales = $this->only('email', 'password');
        $guard = Auth::guard('web');
        $usuario = $guard->getProvider()->retrieveByCredentials($credenciales);

        if (! $usuario || ! $guard->getProvider()->validateCredentials($usuario, $credenciales)) {
            // El mismo evento que dispara Auth::attempt (lo registra la auditoría, sin la contraseña).
            event(new Failed('web', $usuario, $credenciales));
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        // La contraseña es correcta, pero el usuario tiene que estar ACTIVO para entrar.
        if ($motivo = $usuario->motivoAccesoDenegado()) {
            Auditoria::registrar(AccionAuditoria::INICIO_SESION_FALLIDO, 'users', $usuario->getAuthIdentifier(),
                detalle: "Correo: {$this->string('email')}. Contraseña correcta, pero no puede ingresar: {$motivo}",
                usuarioId: $usuario->getAuthIdentifier());

            throw ValidationException::withMessages([
                'email' => $motivo,
            ]);
        }

        $guard->login($usuario, $this->boolean('remember'));
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
