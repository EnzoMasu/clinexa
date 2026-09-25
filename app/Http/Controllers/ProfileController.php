<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Perfil propio: datos de la persona (solo lectura), cambio de contraseña y desactivar la cuenta.
     * Los datos personales y el email no se editan acá: son de la Persona y los administra un admin.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user()->load('persona.tipoDocumento'),
        ]);
    }

    /**
     * "Desactivar mi cuenta": el usuario pasa a INACTIVO (nunca se borra, igual que "Desactivar"
     * en el panel) y se cierra la sesión. La persona asociada no se toca. Para volver a entrar,
     * un administrador tiene que reactivarlo desde Usuarios.
     */
    public function desactivar(Request $request): RedirectResponse
    {
        $request->validateWithBag('desactivarCuenta', [
            'password' => ['required', 'current_password'],
        ]);

        // El sistema no puede quedarse sin ningún administrador que pueda entrar.
        if ($request->user()->esUnicoAdministradorActivo()) {
            throw ValidationException::withMessages([
                'cuenta' => 'No puede desactivar su cuenta: es el único administrador activo del sistema.',
            ])->errorBag('desactivarCuenta');
        }

        $request->user()->update(['estado' => 'INACTIVO']);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::route('login')->with('status', 'Su cuenta fue desactivada. Para volver a usarla, solicite a un administrador que la reactive.');
    }
}
