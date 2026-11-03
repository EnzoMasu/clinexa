<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogoCIE10;
use App\Support\Grilla\Grilla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CatalogoCIE10Controller extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        $grilla = Grilla::de($request)
            ->texto('codigo', 'Código', 'codigo')
            ->texto('descripcion', 'Descripción', 'descripcion')
            ->texto('capitulo', 'Capítulo', 'capitulo')
            ->estado('estado', 'Estado', 'estado_id', CatalogoCIE10::class);

        $codigos = $grilla->aplicar(CatalogoCIE10::query()
            ->when($busqueda !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('codigo', "{$busqueda}%")
                ->orWhereLike('descripcion', "%{$busqueda}%"))), fn ($query) => $query->orderBy('codigo'))
            ->paginate(25)
            ->withQueryString();

        return $this->listado($request, 'admin.cie10', compact('codigos', 'busqueda', 'grilla'));
    }

    public function create(): View
    {
        return view('admin.cie10.form', ['cie10' => new CatalogoCIE10]);
    }

    public function store(Request $request): RedirectResponse
    {
        CatalogoCIE10::create($request->validate([
            'codigo' => ['required', 'string', 'max:10', Rule::unique('catalogo_cie10', 'codigo')],
            ...$this->reglas(),
        ]));

        return redirect()->route('admin.cie10.index')->with('status', 'Código CIE-10 creado.');
    }

    public function edit(CatalogoCIE10 $cie10): View
    {
        return view('admin.cie10.form', compact('cie10'));
    }

    public function update(Request $request, CatalogoCIE10 $cie10): RedirectResponse
    {
        // El código es la PK: no se modifica una vez creado.
        $cie10->update($request->validate([
            ...$this->reglas(),
            'estado_id' => CatalogoCIE10::reglaEstado(),
        ]));

        return redirect()->route('admin.cie10.index')->with('status', 'Código CIE-10 actualizado.');
    }

    public function desactivar(CatalogoCIE10 $cie10): RedirectResponse
    {
        $cie10->desactivar();

        return redirect()->route('admin.cie10.index')->with('status', 'Código CIE-10 desactivado.');
    }

    private function reglas(): array
    {
        return [
            'descripcion' => ['required', 'string', 'max:255'],
            'capitulo' => ['required', 'string', 'max:100'],
        ];
    }
}
