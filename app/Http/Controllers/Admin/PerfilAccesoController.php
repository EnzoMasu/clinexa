<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PerfilAccesoController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.perfiles-acceso', [
            'perfiles' => $this->buscarEn(PerfilAcceso::withCount(['users', 'permisos']), $busqueda, ['nombre', 'descripcion'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.perfiles-acceso.form', $this->datosFormulario(new PerfilAcceso));
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        DB::transaction(function () use ($datos, $request) {
            $perfil = PerfilAcceso::create($datos);
            $this->sincronizarPermisos($perfil, $request->input('permisos', []));
        });

        return redirect()->route('admin.perfiles-acceso.index')->with('status', 'Perfil de acceso creado.');
    }

    public function edit(PerfilAcceso $perfilAcceso): View
    {
        return view('admin.perfiles-acceso.form', $this->datosFormulario($perfilAcceso));
    }

    public function update(Request $request, PerfilAcceso $perfilAcceso): RedirectResponse
    {
        // Administrador: solo se edita la descripción. El nombre queda fijo y la matriz se ignora;
        // se vuelven a asegurar los permisos completos, así nunca queda un admin sin acceso.
        if ($perfilAcceso->esAdministrador()) {
            $datos = $request->validate([
                'nombre' => ['required', Rule::in([$perfilAcceso->nombre])],
                'descripcion' => ['nullable', 'string', 'max:200'],
            ], ['nombre.in' => 'El nombre del perfil Administrador no se puede cambiar.'], ['descripcion' => 'descripción']);

            DB::transaction(function () use ($datos, $perfilAcceso) {
                $perfilAcceso->update(Arr::only($datos, ['descripcion']));
                PerfilAcceso::asegurarAdministrador();
            });

            return redirect()->route('admin.perfiles-acceso.index')->with('status', 'Perfil de acceso actualizado.');
        }

        $datos = $this->validar($request, $perfilAcceso);

        DB::transaction(function () use ($datos, $request, $perfilAcceso) {
            $perfilAcceso->update($datos);
            $this->sincronizarPermisos($perfilAcceso, $request->input('permisos', []));
        });

        return redirect()->route('admin.perfiles-acceso.index')->with('status', 'Perfil de acceso actualizado.');
    }

    /**
     * Baja lógica: el perfil pasa a INACTIVO y sus usuarios dejan de poder entrar.
     * El perfil Administrador no se puede desactivar (el sistema quedaría sin administración).
     */
    public function desactivar(PerfilAcceso $perfilAcceso): RedirectResponse
    {
        if ($perfilAcceso->esAdministrador()) {
            return back()->with('error', 'El perfil Administrador no se puede desactivar.');
        }

        $perfilAcceso->desactivar();

        return redirect()->route('admin.perfiles-acceso.index')
            ->with('status', 'Perfil de acceso desactivado. Sus usuarios pierden sus permisos; quien no tenga otro perfil activo ya no puede ingresar al sistema.');
    }

    private function datosFormulario(PerfilAcceso $perfil): array
    {
        return [
            'perfil' => $perfil,
            'modulos' => ModuloSistema::orderBy('nombre')->get(),
            // "modulo_id:ACCION" de cada permiso que el perfil ya tiene, para tildar la matriz.
            'asignados' => $perfil->exists
                ? $perfil->permisos->map(fn (Permiso $permiso) => "{$permiso->modulo_sistema_id}:{$permiso->accion}")->all()
                : [],
            'matrizFija' => $perfil->esAdministrador(),
        ];
    }

    /**
     * $matriz viene del formulario como [modulo_id => ['VER', 'EDITAR', ...]].
     * Crea en permisos las combinaciones módulo + acción que todavía no existan y deja
     * en perfil_permiso exactamente las tildadas. Los permisos destildados solo se quitan
     * del perfil: el registro en permisos queda, porque puede estar asignado a otros perfiles.
     */
    private function sincronizarPermisos(PerfilAcceso $perfil, array $matriz): void
    {
        $codigos = ModuloSistema::pluck('codigo', 'id');

        $permisoIds = collect($matriz)
            // Las acciones que no aplican al módulo (CREAR en Auditoría, etc.) se ignoran.
            ->map(fn (array $acciones, $moduloId) => array_intersect($acciones, Permiso::accionesDe($codigos[$moduloId] ?? '')))
            ->flatMap(fn (array $acciones, $moduloId) => collect($acciones)->map(
                fn (string $accion) => Permiso::firstOrCreate(['modulo_sistema_id' => $moduloId, 'accion' => $accion])->id
            ));

        $perfil->sincronizarAuditado('permisos', $permisoIds->all());
    }

    /**
     * Sin escalada de privilegios: los permisos que se AGREGAN al perfil (no los que ya tenía) tienen que ser
     * permisos que quien edita ya tiene. El Administrador tiene todo.
     */
    private function validarSinEscalada(Request $request, ?PerfilAcceso $perfil): void
    {
        $codigos = ModuloSistema::pluck('codigo', 'id');
        $pedidos = collect((array) $request->input('permisos', []))
            ->flatMap(fn ($acciones, $moduloId) => collect((array) $acciones)
                ->filter(fn ($accion) => in_array($accion, Permiso::accionesDe($codigos[$moduloId] ?? ''), true))
                ->map(fn ($accion) => ($codigos[$moduloId] ?? '?').":{$accion}"))
            ->unique()->values()->all();
        $agregados = array_values(array_diff($pedidos, $perfil?->clavesPermisos() ?? []));

        if (! $request->user()->tieneTodos($agregados)) {
            $ajenos = array_diff($agregados, array_keys($request->user()->permisosEfectivos()));
            throw ValidationException::withMessages([
                'permisos' => 'No puede agregar permisos que usted no tiene: '.implode(', ', array_map(fn ($clave) => str_replace(':', ': ', $clave), $ajenos)).'.',
            ]);
        }
    }

    /**
     * Valida el formulario completo y devuelve solo los campos del perfil (la matriz se procesa aparte).
     */
    private function validar(Request $request, ?PerfilAcceso $perfil = null): array
    {
        $this->validarSinEscalada($request, $perfil);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:50', Rule::unique('perfiles_acceso')->ignore($perfil)],
            'descripcion' => ['nullable', 'string', 'max:200'],
            'permisos' => ['array', function (string $atributo, mixed $valor, \Closure $fail) {
                $inexistentes = is_array($valor) && array_diff(array_keys($valor), ModuloSistema::pluck('id')->all());
                if ($inexistentes) {
                    $fail('La matriz de permisos incluye un módulo inexistente.');
                }
            }],
            'permisos.*' => ['array'],
            'permisos.*.*' => [Rule::in(Permiso::ACCIONES)],
            // Al crear, el perfil nace con el estado inicial del módulo; se elige al editar.
            'estado_id' => $perfil ? PerfilAcceso::reglaEstado() : ['prohibited'],
        ], [], ['descripcion' => 'descripción']);

        return Arr::only($datos, ['nombre', 'descripcion', 'estado_id']);
    }
}
