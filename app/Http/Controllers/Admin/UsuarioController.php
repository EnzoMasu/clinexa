<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PerfilAcceso;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        $usuarios = User::query()
            ->with('perfilAcceso')
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('name', "%{$busqueda}%")
                ->orWhereLike('email', "%{$busqueda}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.usuarios', compact('usuarios', 'busqueda'));
    }

    public function create(): View
    {
        return view('admin.usuarios.form', [
            'usuario' => new User,
            'perfiles' => PerfilAcceso::orderBy('nombre')->pluck('nombre', 'id'),
        ]);
    }

    /**
     * El admin no define la contraseña: se guarda una aleatoria que nadie conoce y se le
     * manda al usuario el email de restablecer contraseña para que elija la suya.
     */
    public function store(Request $request): RedirectResponse
    {
        $usuario = User::create([
            ...$this->validar($request),
            'password' => Str::password(32),
        ]);

        return $this->enviarLinkContrasena($usuario, 'Usuario creado.');
    }

    public function edit(User $usuario): View
    {
        return view('admin.usuarios.form', [
            'usuario' => $usuario,
            'perfiles' => PerfilAcceso::orderBy('nombre')->pluck('nombre', 'id'),
        ]);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $esUnoMismo = $usuario->is($request->user());

        if ($esUnoMismo) {
            // En el formulario el perfil propio viene deshabilitado (no se envía): se completa con el actual.
            if ($request->filled('perfil_acceso_id') && (int) $request->input('perfil_acceso_id') !== $usuario->perfil_acceso_id) {
                return back()->withInput()->with('error', 'No puede cambiar su propio perfil de acceso.');
            }

            $request->merge(['perfil_acceso_id' => $usuario->perfil_acceso_id]);
        }

        $datos = $this->validar($request, $usuario);

        if ($esUnoMismo && $datos['estado'] !== 'ACTIVO') {
            return back()->withInput()->with('error', 'No puede bloquear ni desactivar su propio usuario.');
        }

        $usuario->update($datos);

        return redirect()->route('admin.usuarios.index')->with('status', 'Usuario actualizado.');
    }

    public function desactivar(Request $request, User $usuario): RedirectResponse
    {
        if ($usuario->is($request->user())) {
            return back()->with('error', 'No puede desactivar su propio usuario.');
        }

        $usuario->update(['estado' => 'INACTIVO']);

        return redirect()->route('admin.usuarios.index')->with('status', 'Usuario desactivado.');
    }

    public function enviarInvitacion(User $usuario): RedirectResponse
    {
        return $this->enviarLinkContrasena($usuario);
    }

    private function enviarLinkContrasena(User $usuario, string $prefijo = ''): RedirectResponse
    {
        $status = $usuario->enviarLinkContrasena();
        $redirect = redirect()->route('admin.usuarios.index');

        if ($status !== Password::RESET_LINK_SENT) {
            // Típicamente RESET_THROTTLED: ya se mandó uno hace menos de un minuto.
            return $redirect->with('error', trim("{$prefijo} No se pudo enviar el email: ".__($status)));
        }

        return $redirect->with('status', trim("{$prefijo} Se envió a {$usuario->email} el link para definir la contraseña."));
    }

    private function validar(Request $request, ?User $usuario = null): array
    {
        $reglas = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($usuario)],
            'perfil_acceso_id' => ['required', 'integer', Rule::exists('perfiles_acceso', 'id')],
        ];

        if ($usuario) {
            $reglas['estado'] = ['required', Rule::in(['ACTIVO', 'BLOQUEADO', 'INACTIVO'])];
        }

        return $request->validate($reglas, [], [
            'name' => 'nombre',
            'perfil_acceso_id' => 'perfil de acceso',
        ]);
    }
}
