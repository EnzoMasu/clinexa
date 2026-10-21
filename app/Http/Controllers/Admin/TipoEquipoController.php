<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipoEquipo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Tipos de equipo (docs/reparto-economico.md): catálogo simple, nace vacío. No se borra: se desactiva. */
class TipoEquipoController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.tipos-equipo', [
            'tiposEquipo' => $this->buscarEn(TipoEquipo::query(), $busqueda, ['nombre'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.tipos-equipo.form', ['tipoEquipo' => new TipoEquipo]);
    }

    public function store(Request $request): RedirectResponse
    {
        TipoEquipo::create($this->validar($request));

        return redirect()->route('admin.tipos-equipo.index')->with('status', 'Tipo de equipo creado.');
    }

    public function edit(TipoEquipo $tipoEquipo): View
    {
        return view('admin.tipos-equipo.form', compact('tipoEquipo'));
    }

    public function update(Request $request, TipoEquipo $tipoEquipo): RedirectResponse
    {
        $tipoEquipo->update($this->validar($request, $tipoEquipo));

        return redirect()->route('admin.tipos-equipo.index')->with('status', 'Tipo de equipo actualizado.');
    }

    public function desactivar(TipoEquipo $tipoEquipo): RedirectResponse
    {
        $tipoEquipo->desactivar();

        return redirect()->route('admin.tipos-equipo.index')->with('status', 'Tipo de equipo desactivado.');
    }

    private function validar(Request $request, ?TipoEquipo $tipoEquipo = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('tipos_equipo')->ignore($tipoEquipo)],
        ];

        if ($tipoEquipo) {
            $reglas['estado_id'] = TipoEquipo::reglaEstado();
        }

        return $request->validate($reglas);
    }
}
