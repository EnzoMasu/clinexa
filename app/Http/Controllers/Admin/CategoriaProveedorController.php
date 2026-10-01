<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CategoriaProveedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoriaProveedorController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.categorias-proveedor', [
            'categoriasProveedor' => $this->buscarEn(CategoriaProveedor::query(), $busqueda, ['nombre'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.categorias-proveedor.form', ['categoriaProveedor' => new CategoriaProveedor]);
    }

    public function store(Request $request): RedirectResponse
    {
        CategoriaProveedor::create($this->validar($request));

        return redirect()->route('admin.categorias-proveedor.index')->with('status', 'Categoría de proveedor creada.');
    }

    public function edit(CategoriaProveedor $categoriaProveedor): View
    {
        return view('admin.categorias-proveedor.form', compact('categoriaProveedor'));
    }

    public function update(Request $request, CategoriaProveedor $categoriaProveedor): RedirectResponse
    {
        $categoriaProveedor->update($this->validar($request, $categoriaProveedor));

        return redirect()->route('admin.categorias-proveedor.index')->with('status', 'Categoría de proveedor actualizada.');
    }

    public function desactivar(CategoriaProveedor $categoriaProveedor): RedirectResponse
    {
        $categoriaProveedor->desactivar();

        return redirect()->route('admin.categorias-proveedor.index')->with('status', 'Categoría de proveedor desactivada.');
    }

    private function validar(Request $request, ?CategoriaProveedor $categoriaProveedor = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('categorias_proveedor')->ignore($categoriaProveedor)],
        ];

        if ($categoriaProveedor) {
            $reglas['estado_id'] = CategoriaProveedor::reglaEstado();
        }

        return $request->validate($reglas);
    }
}
