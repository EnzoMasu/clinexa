<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogoCIE10;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CatalogoCIE10Controller extends Controller
{
    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->query('q'));

        $codigos = CatalogoCIE10::query()
            ->when($busqueda !== '', fn ($query) => $query
                ->whereLike('codigo', "{$busqueda}%")
                ->orWhereLike('descripcion', "%{$busqueda}%"))
            ->orderBy('codigo')
            ->paginate(25)
            ->withQueryString();

        return view('admin.cie10.index', compact('codigos', 'busqueda'));
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
        $cie10->update($request->validate($this->reglas()));

        return redirect()->route('admin.cie10.index')->with('status', 'Código CIE-10 actualizado.');
    }

    private function reglas(): array
    {
        return [
            'descripcion' => ['required', 'string', 'max:255'],
            'capitulo' => ['required', 'string', 'max:100'],
        ];
    }
}
