<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipoIndicacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TipoIndicacionController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.tipos-indicacion', [
            'tiposIndicacion' => $this->buscarEn(TipoIndicacion::query(), $busqueda, ['nombre'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.tipos-indicacion.form', ['tipoIndicacion' => new TipoIndicacion]);
    }

    public function store(Request $request): RedirectResponse
    {
        TipoIndicacion::create($this->validar($request));

        return redirect()->route('admin.tipos-indicacion.index')->with('status', 'Tipo de indicación creado.');
    }

    public function edit(TipoIndicacion $tipoIndicacion): View
    {
        return view('admin.tipos-indicacion.form', ['tipoIndicacion' => $tipoIndicacion]);
    }

    public function update(Request $request, TipoIndicacion $tipoIndicacion): RedirectResponse
    {
        $tipoIndicacion->update($this->validar($request, $tipoIndicacion));

        return redirect()->route('admin.tipos-indicacion.index')->with('status', 'Tipo de indicación actualizado.');
    }

    public function desactivar(TipoIndicacion $tipoIndicacion): RedirectResponse
    {
        $tipoIndicacion->desactivar();

        return redirect()->route('admin.tipos-indicacion.index')->with('status', 'Tipo de indicación desactivado.');
    }

    private function validar(Request $request, ?TipoIndicacion $tipoIndicacion = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('tipos_indicacion')->ignore($tipoIndicacion)],
        ];

        if ($tipoIndicacion) {
            $reglas['estado_id'] = TipoIndicacion::reglaEstado();
        }

        return $request->validate($reglas);
    }
}
