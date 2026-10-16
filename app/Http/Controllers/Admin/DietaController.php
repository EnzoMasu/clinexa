<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dieta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Catálogo de dietas (docs/indicaciones-generales.md). Lo administra quien tiene el módulo DIETAS (Administrador y
 * Supervisión médica); el Médico solo las usa desde la indicación general. No se borra: se desactiva.
 */
class DietaController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.dietas', [
            'dietas' => $this->buscarEn(Dieta::query(), $busqueda, ['nombre'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.dietas.form', ['dieta' => new Dieta]);
    }

    public function store(Request $request): RedirectResponse
    {
        Dieta::create($this->validar($request));

        return redirect()->route('admin.dietas.index')->with('status', 'Dieta creada.');
    }

    public function edit(Dieta $dieta): View
    {
        return view('admin.dietas.form', ['dieta' => $dieta]);
    }

    public function update(Request $request, Dieta $dieta): RedirectResponse
    {
        $dieta->update($this->validar($request, $dieta));

        return redirect()->route('admin.dietas.index')->with('status', 'Dieta actualizada. Las indicaciones ya guardadas no cambian.');
    }

    public function desactivar(Dieta $dieta): RedirectResponse
    {
        $dieta->desactivar();

        return redirect()->route('admin.dietas.index')->with('status', 'Dieta desactivada.');
    }

    private function validar(Request $request, ?Dieta $dieta = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('dietas')->ignore($dieta)],
            'texto' => ['required', 'string', 'max:'.Dieta::MAXIMO_TEXTO],
        ];

        if ($dieta) {
            $reglas['estado_id'] = Dieta::reglaEstado();
        }

        return $request->validate($reglas, [], ['texto' => 'texto de la dieta']);
    }
}
