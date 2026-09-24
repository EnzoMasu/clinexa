<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la sesión de un usuario que fue bloqueado o desactivado mientras estaba logueado.
 */
class UsuarioActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($motivo = $request->user()?->motivoAccesoDenegado()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $motivo]);
        }

        return $next($request);
    }
}
