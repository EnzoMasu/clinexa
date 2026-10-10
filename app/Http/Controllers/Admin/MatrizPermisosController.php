<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModuloSistema;
use App\Models\PerfilAcceso;
use App\Models\Permiso;
use Illuminate\View\View;

/**
 * Matriz de permisos: perfiles en columnas, módulos en filas y, en cada celda, las acciones del perfil sobre
 * ese módulo. Solo lectura (VER sobre PERFILES_ACCESO; es un módulo sensible: abrirla registra VER). Los
 * permisos se editan en cada perfil.
 */
class MatrizPermisosController extends Controller
{
    public function index(): View
    {
        $perfiles = PerfilAcceso::with('permisos')->orderByDesc('predefinido')->orderBy('nombre')->get();

        return view('admin.matriz-permisos.index', [
            'perfiles' => $perfiles,
            'modulos' => ModuloSistema::orderBy('nombre')->get(),
            // [perfil_id][modulo_id] => ['VER', 'CREAR', ...], en el orden de Permiso::ACCIONES.
            'matriz' => $perfiles->mapWithKeys(fn (PerfilAcceso $perfil) => [
                $perfil->id => $perfil->permisos->groupBy('modulo_sistema_id')
                    ->map(fn ($permisos) => array_values(array_intersect(Permiso::ACCIONES, $permisos->pluck('accion')->all()))),
            ]),
        ]);
    }
}
