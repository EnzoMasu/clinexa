<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CategoriaGasto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoriaGastoController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.categorias-gasto', [
            'categoriasGasto' => $this->buscarEn(CategoriaGasto::query(), $busqueda, ['nombre'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.categorias-gasto.form', ['categoriaGasto' => new CategoriaGasto]);
    }

    public function store(Request $request): RedirectResponse
    {
        CategoriaGasto::create($this->validar($request));

        return redirect()->route('admin.categorias-gasto.index')->with('status', 'Categoría de gasto creada.');
    }

    public function edit(CategoriaGasto $categoriaGasto): View
    {
        return view('admin.categorias-gasto.form', compact('categoriaGasto'));
    }

    public function update(Request $request, CategoriaGasto $categoriaGasto): RedirectResponse
    {
        $categoriaGasto->update($this->validar($request, $categoriaGasto));

        return redirect()->route('admin.categorias-gasto.index')->with('status', 'Categoría de gasto actualizada.');
    }

    private function validar(Request $request, ?CategoriaGasto $categoriaGasto = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('categorias_gasto')->ignore($categoriaGasto)],
        ]);
    }
}
