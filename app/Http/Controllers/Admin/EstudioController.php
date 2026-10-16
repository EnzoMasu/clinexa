<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Estudio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Catálogo de estudios (docs/ordenes-estudio.md). Lo administra quien tiene el módulo ESTUDIOS (Administrador y Supervisión
 * médica); el Médico solo lo usa desde la orden de estudio. No se borra: se desactiva.
 */
class EstudioController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.estudios', [
            'estudios' => $this->buscarEn(Estudio::query(), $busqueda, ['nombre'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.estudios.form', ['estudio' => new Estudio]);
    }

    public function store(Request $request): RedirectResponse
    {
        Estudio::create($this->validar($request));

        return redirect()->route('admin.estudios.index')->with('status', 'Estudio creado.');
    }

    public function edit(Estudio $estudio): View
    {
        return view('admin.estudios.form', ['estudio' => $estudio]);
    }

    public function update(Request $request, Estudio $estudio): RedirectResponse
    {
        $estudio->update($this->validar($request, $estudio));

        return redirect()->route('admin.estudios.index')->with('status', 'Estudio actualizado. Las órdenes ya emitidas no cambian.');
    }

    public function desactivar(Estudio $estudio): RedirectResponse
    {
        $estudio->desactivar();

        return redirect()->route('admin.estudios.index')->with('status', 'Estudio desactivado.');
    }

    private function validar(Request $request, ?Estudio $estudio = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:'.Estudio::MAXIMO_NOMBRE, Rule::unique('estudios')->ignore($estudio)],
        ];

        if ($estudio) {
            $reglas['estado_id'] = Estudio::reglaEstado();
        }

        return $request->validate($reglas);
    }
}
