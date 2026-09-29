<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Estado;
use App\Models\PerfilAcceso;
use App\Models\Persona;
use App\Models\User;
use App\Support\BuscadorPersonas;
use Illuminate\Http\JsonResponse;
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

        // Nombre y documento vienen de la persona; el email se busca en users (es copia del de la persona).
        $usuarios = User::query()
            ->select('users.*')
            ->join('personas', 'personas.id', '=', 'users.persona_id')
            ->with(['persona.tipoDocumento', 'perfilAcceso'])
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('personas.apellidos', "%{$busqueda}%")
                ->orWhereLike('personas.nombres', "%{$busqueda}%")
                ->orWhereLike('personas.nro_documento', "{$busqueda}%")
                ->orWhereLike('users.email', "%{$busqueda}%")))
            ->orderBy('personas.apellidos')
            ->orderBy('personas.nombres')
            ->paginate(20)
            ->withQueryString();

        return $this->listado($request, 'admin.usuarios', compact('usuarios', 'busqueda'));
    }

    public function create(): View
    {
        return view('admin.usuarios.form', [
            'usuario' => new User,
            // La persona se elige con el buscador; acá solo importa si queda alguna para elegir.
            'hayPersonasDisponibles' => Persona::disponiblesParaUsuario()->exists(),
            'perfiles' => $this->perfiles(),
        ]);
    }

    /**
     * Buscador de personas del alta (JSON): físicas, activas y sin usuario. Mismo buscador que
     * los roles (BuscadorPersonas: mínimo 2 caracteres, hasta 15 resultados).
     */
    public function personasDisponibles(Request $request): JsonResponse
    {
        return BuscadorPersonas::responder(Persona::disponiblesParaUsuario(), (string) $request->query('q'));
    }

    /**
     * El usuario es una persona existente: su email sale de ella. El admin no define la contraseña:
     * se guarda una aleatoria que nadie conoce y se le manda el link para que elija la suya.
     */
    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'persona_id' => ['required', 'integer', function (string $atributo, mixed $valor, \Closure $fail) {
                $persona = Persona::disponiblesParaUsuario()->find($valor);
                if (! $persona) {
                    $fail('La persona elegida no existe, no está activa, no es una persona física o ya tiene un usuario.');
                } elseif (User::where('email', Persona::emailDeUsuario($persona->email))->exists()) {
                    $fail("El email de esa persona ({$persona->email}) ya lo usa otro usuario del sistema.");
                }
            }],
            'perfil_acceso_id' => ['required', 'integer', Rule::exists('perfiles_acceso', 'id')->where('estado_id', Estado::idDe(Estado::ACTIVO))],
        ], [], ['persona_id' => 'persona', 'perfil_acceso_id' => 'perfil de acceso']);

        $usuario = User::create([
            ...$datos,
            'email' => Persona::emailDeUsuario(Persona::findOrFail($datos['persona_id'])->email),
            'password' => Str::password(32),
        ]);

        return $this->enviarLinkContrasena($usuario, 'Usuario creado.');
    }

    public function edit(User $usuario): View
    {
        return view('admin.usuarios.form', [
            'usuario' => $usuario->load('persona.tipoDocumento'),
            'perfiles' => $this->perfiles($usuario),
        ]);
    }

    /**
     * Solo se editan perfil y estado: la persona no cambia una vez creado el usuario (para no
     * mezclar identidades) y el nombre/email se editan en la persona.
     */
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

        $datos = $request->validate([
            // Un perfil activo, o el que el usuario ya tenía (aunque se haya desactivado).
            'perfil_acceso_id' => ['required', 'integer', Rule::exists('perfiles_acceso', 'id')->where(fn ($query) => $query
                ->where('estado_id', Estado::idDe(Estado::ACTIVO))
                ->orWhere('id', $usuario->perfil_acceso_id))],
            'estado_id' => User::reglaEstado(),
        ], [], ['perfil_acceso_id' => 'perfil de acceso']);

        if ($esUnoMismo && (int) $datos['estado_id'] !== Estado::idDe(Estado::ACTIVO)) {
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

        $usuario->desactivar();

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

    /**
     * Perfiles activos para elegir, más el que el usuario ya tenga aunque esté inactivo.
     */
    private function perfiles(?User $usuario = null): array
    {
        return PerfilAcceso::query()
            ->where(fn ($query) => $query->activos()
                ->when($usuario, fn ($query) => $query->orWhere('id', $usuario->perfil_acceso_id)))
            ->orderBy('nombre')->pluck('nombre', 'id')->all();
    }
}
