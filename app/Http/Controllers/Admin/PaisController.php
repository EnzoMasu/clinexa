<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pais;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaisController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.paises', [
            'paises' => $this->buscarEn(Pais::query()->withCount('departamentos'), $busqueda, ['nombre', 'codigo'])
                ->orderBy('nombre')->paginate(20)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.paises.form', ['pais' => new Pais]);
    }

    public function store(Request $request): RedirectResponse
    {
        Pais::create($this->validar($request));

        return redirect()->route('admin.paises.index')->with('status', 'País creado.');
    }

    public function edit(Pais $pais): View
    {
        return view('admin.paises.form', compact('pais'));
    }

    public function update(Request $request, Pais $pais): RedirectResponse
    {
        $pais->update($this->validar($request, $pais));

        return redirect()->route('admin.paises.index')->with('status', 'País actualizado.');
    }

    public function desactivar(Pais $pais): RedirectResponse
    {
        $pais->desactivar();

        return redirect()->route('admin.paises.index')->with('status', 'País desactivado.');
    }

    private function validar(Request $request, ?Pais $pais = null): array
    {
        // El código ISO se guarda en mayúsculas ("py" -> "PY").
        $request->merge(['codigo' => mb_strtoupper(trim((string) $request->input('codigo')))]);

        $reglas = [
            'codigo' => ['required', 'regex:/^[A-Z]{2}$/', Rule::unique('paises')->ignore($pais)],
            'nombre' => ['required', 'string', 'max:100', Rule::unique('paises')->ignore($pais)],
        ];

        if ($pais) {
            $reglas['estado_id'] = Pais::reglaEstado();
        }

        return $request->validate($reglas, [
            'codigo.regex' => 'El código debe ser el ISO de 2 letras (por ejemplo, PY).',
        ], ['codigo' => 'código']);
    }
}
