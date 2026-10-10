<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UltimoAdministrador;
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
use Illuminate\Support\Facades\DB;
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
            ->with(['persona.tipoDocumento', 'perfiles'])
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
     * los roles (BuscadorPersonas: mínimo 2 caracteres, hasta 15 resultados), pero muestra también
     * el email, que es el que va a tener el usuario.
     */
    public function personasDisponibles(Request $request): JsonResponse
    {
        return BuscadorPersonas::responder(Persona::disponiblesParaUsuario(), (string) $request->query('q'), conEmail: true);
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
            ...$this->reglasPerfiles(),
        ], $this->mensajesPerfiles(), ['persona_id' => 'persona', 'perfiles' => 'perfiles de acceso']);

        $perfiles = $this->perfilesElegidos($datos['perfiles']);
        if ($error = $this->errorDeAsignacion($request->user(), $perfiles, [])) {
            return back()->withInput()->withErrors(['perfiles' => $error]);
        }

        $usuario = DB::transaction(function () use ($datos, $perfiles) {
            $usuario = User::create([
                'persona_id' => $datos['persona_id'],
                'email' => Persona::emailDeUsuario(Persona::findOrFail($datos['persona_id'])->email),
                'password' => Str::password(32),
            ]);
            // Queda en el log como EDITAR del usuario: perfiles de [] a la lista elegida.
            $usuario->sincronizarAuditado('perfiles', $perfiles->modelKeys());

            return $usuario;
        });

        return $this->enviarLinkContrasena($usuario, 'Usuario creado.');
    }

    public function edit(User $usuario): View
    {
        return view('admin.usuarios.form', [
            'usuario' => $usuario->load('persona.tipoDocumento', 'perfiles'),
            'perfiles' => $this->perfiles($usuario),
        ]);
    }

    /**
     * Solo se editan perfiles y estado: la persona no cambia una vez creado el usuario (para no
     * mezclar identidades) y el nombre/email se editan en la persona. Los perfiles propios también se
     * pueden cambiar, con las mismas reglas: sin escalar privilegios y sin dejar el sistema sin administrador.
     */
    public function update(Request $request, User $usuario): RedirectResponse
    {
        $datos = $request->validate([
            ...$this->reglasPerfiles($usuario),
            'estado_id' => User::reglaEstado(),
        ], $this->mensajesPerfiles(), ['perfiles' => 'perfiles de acceso']);

        if ($usuario->is($request->user()) && (int) $datos['estado_id'] !== Estado::idDe(Estado::ACTIVO)) {
            return back()->withInput()->with('error', 'No puede bloquear ni desactivar su propio usuario.');
        }

        $perfiles = $this->perfilesElegidos($datos['perfiles']);
        $actuales = $usuario->perfiles()->pluck('perfiles_acceso.id')->all();
        $activo = (int) $datos['estado_id'] === Estado::idDe(Estado::ACTIVO);
        if ($error = $this->errorDeAsignacion($request->user(), $perfiles, $actuales, $activo)) {
            return back()->withInput()->withErrors(['perfiles' => $error]);
        }

        DB::transaction(function () use ($usuario, $datos, $perfiles) {
            // Quitarle el perfil Administrador al último administrador activo: no (se deshace todo).
            $habiaAdministrador = User::administradoresActivos()->exists();
            $usuario->sincronizarAuditado('perfiles', $perfiles->modelKeys());
            $usuario->olvidarPermisos();
            if ($habiaAdministrador && ! User::administradoresActivos()->exists()) {
                throw new UltimoAdministrador;
            }
            // Desactivarlo o bloquearlo lo rechaza el modelo (User::booted).
            $usuario->update(['estado_id' => $datos['estado_id']]);
        });
        $request->user()->olvidarPermisos();

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
     * Perfiles para las casillas: los activos, más los inactivos que el usuario ya tenga (se muestran con
     * su estado; no aportan permisos).
     */
    private function perfiles(?User $usuario = null): \Illuminate\Support\Collection
    {
        $asignados = $usuario?->perfiles()->pluck('perfiles_acceso.id')->all() ?? [];

        return PerfilAcceso::query()
            ->where(fn ($query) => $query->activos()->orWhereIn('id', $asignados))
            ->orderBy('nombre')->get();
    }

    /** Reglas de las casillas: al menos uno; cada uno activo, o uno inactivo que el usuario ya tenía. */
    private function reglasPerfiles(?User $usuario = null): array
    {
        $asignados = $usuario?->perfiles()->pluck('perfiles_acceso.id')->all() ?? [];

        return [
            'perfiles' => ['required', 'array', 'min:1'],
            'perfiles.*' => ['integer', 'distinct', Rule::exists('perfiles_acceso', 'id')->where(fn ($query) => $query
                ->where('estado_id', Estado::idDe(Estado::ACTIVO))
                ->orWhereIn('id', $asignados))],
        ];
    }

    private function mensajesPerfiles(): array
    {
        return [
            'perfiles.required' => 'Elija al menos un perfil de acceso.',
            'perfiles.min' => 'Elija al menos un perfil de acceso.',
            'perfiles.*.exists' => 'Uno de los perfiles elegidos no existe o está inactivo.',
        ];
    }

    private function perfilesElegidos(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        return PerfilAcceso::whereIn('id', array_map('intval', $ids))->get();
    }

    /**
     * Por qué no se puede guardar esa asignación, o null:
     * - un usuario ACTIVO necesita al menos un perfil ACTIVO;
     * - sin escalada de privilegios: cada perfil que se AGREGA (no los que ya tenía) tiene que tener solo
     *   permisos que quien asigna ya tiene, también si se asigna a sí mismo (el Administrador tiene todo).
     */
    private function errorDeAsignacion(User $quien, \Illuminate\Database\Eloquent\Collection $perfiles, array $actuales, bool $activo = true): ?string
    {
        if ($activo && $perfiles->filter(fn (PerfilAcceso $perfil) => $perfil->estaActivo())->isEmpty()) {
            return 'Un usuario activo necesita al menos un perfil de acceso activo.';
        }

        $ajeno = $perfiles->reject(fn (PerfilAcceso $perfil) => in_array($perfil->id, $actuales, true))
            ->first(fn (PerfilAcceso $perfil) => ! $quien->puedeAsignarPerfil($perfil));

        return $ajeno ? "No puede asignar el perfil «{$ajeno->nombre}»: tiene permisos que usted no tiene." : null;
    }
}
