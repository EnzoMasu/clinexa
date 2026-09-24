<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PerfilAccesoController extends Controller
{
    public function index(): View
    {
        return view('admin.perfiles-acceso.index', [
            'perfiles' => PerfilAcceso::withCount(['users', 'permisos'])->orderBy('nombre')->paginate(15),
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
                'nombre' => ['required', Rule::in([PerfilAcceso::ADMINISTRADOR])],
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
        $permisoIds = collect($matriz)
            ->flatMap(fn (array $acciones, $moduloId) => collect($acciones)->map(
                fn (string $accion) => Permiso::firstOrCreate(['modulo_sistema_id' => $moduloId, 'accion' => $accion])->id
            ));

        $perfil->permisos()->sync($permisoIds->all());
    }

    /**
     * Valida el formulario completo y devuelve solo los campos del perfil (la matriz se procesa aparte).
     */
    private function validar(Request $request, ?PerfilAcceso $perfil = null): array
    {
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
        ], [], ['descripcion' => 'descripción']);

        return Arr::only($datos, ['nombre', 'descripcion']);
    }
}
