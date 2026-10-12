<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use App\Support\PermisosDePerfil;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        [$datos, $claves] = $this->validar($request);

        DB::transaction(function () use ($datos, $claves) {
            $perfil = PerfilAcceso::create($datos);
            PermisosDePerfil::guardar($perfil, $claves);
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

        [$datos, $claves] = $this->validar($request, $perfilAcceso);

        DB::transaction(function () use ($datos, $claves, $perfilAcceso) {
            $perfilAcceso->update($datos);
            PermisosDePerfil::guardar($perfilAcceso, $claves);
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
     * Valida el formulario completo. Devuelve [campos del perfil, claves de permisos pedidas]. La matriz la lee
     * PermisosDePerfil (las acciones sin efecto en el módulo se ignoran: en este formulario no tienen casilla) y
     * aplica la regla de escalada: solo se agregan permisos que quien edita ya tiene.
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
            // Al crear, el perfil nace con el estado inicial del módulo; se elige al editar.
            'estado_id' => $perfil ? PerfilAcceso::reglaEstado() : ['prohibited'],
        ], [], ['descripcion' => 'descripción']);

        $claves = PermisosDePerfil::desdeFormulario($request->input('permisos', []), estricto: false);
        [$agregadas] = PermisosDePerfil::cambios($perfil ? PermisosDePerfil::claves($perfil) : [], $claves);
        PermisosDePerfil::validarSinEscalada($request->user(), $agregadas);

        return [Arr::only($datos, ['nombre', 'descripcion', 'estado_id']), $claves];
    }
}
