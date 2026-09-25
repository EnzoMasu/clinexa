<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Especialidad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EspecialidadController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.especialidades', [
            'especialidades' => $this->buscarEn(Especialidad::query(), $busqueda, ['nombre', 'descripcion'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.especialidades.form', ['especialidad' => new Especialidad]);
    }

    public function store(Request $request): RedirectResponse
    {
        Especialidad::create($this->validar($request));

        return redirect()->route('admin.especialidades.index')->with('status', 'Especialidad creada.');
    }

    public function edit(Especialidad $especialidad): View
    {
        return view('admin.especialidades.form', compact('especialidad'));
    }

    public function update(Request $request, Especialidad $especialidad): RedirectResponse
    {
        $especialidad->update($this->validar($request, $especialidad));

        return redirect()->route('admin.especialidades.index')->with('status', 'Especialidad actualizada.');
    }

    private function validar(Request $request, ?Especialidad $especialidad = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('especialidades')->ignore($especialidad)],
            'descripcion' => ['nullable', 'string'],
        ]);
    }
}
