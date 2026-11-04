<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Especialidad;
use App\Support\Grilla\Grilla;
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
        $grilla = Grilla::de($request)
            ->texto('nombre', 'Nombre', 'nombre')
            ->texto('descripcion', 'Descripción', 'descripcion')
            ->estado('estado', 'Estado', 'estado_id', Especialidad::class);

        return $this->listado($request, 'admin.especialidades', [
            'especialidades' => $grilla->aplicar($this->buscarEn(Especialidad::query(), $busqueda, ['nombre', 'descripcion']), fn ($query) => $query->orderBy('nombre'))
                ->paginate(15)->withQueryString(),
            'grilla' => $grilla,
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

    public function desactivar(Especialidad $especialidad): RedirectResponse
    {
        $especialidad->desactivar();

        return redirect()->route('admin.especialidades.index')->with('status', 'Especialidad desactivada.');
    }

    private function validar(Request $request, ?Especialidad $especialidad = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('especialidades')->ignore($especialidad)],
            'descripcion' => ['nullable', 'string'],
        ];

        if ($especialidad) {
            $reglas['estado_id'] = Especialidad::reglaEstado();
        }

        return $request->validate($reglas);
    }
}
