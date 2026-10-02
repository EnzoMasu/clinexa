<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrigenTurno;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrigenTurnoController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.origenes-turno', [
            'origenesTurno' => $this->buscarEn(OrigenTurno::query(), $busqueda, ['codigo', 'nombre'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.origenes-turno.form', ['origenTurno' => new OrigenTurno]);
    }

    public function store(Request $request): RedirectResponse
    {
        OrigenTurno::create($this->validar($request));

        return redirect()->route('admin.origenes-turno.index')->with('status', 'Origen de turno creado.');
    }

    public function edit(OrigenTurno $origenTurno): View
    {
        return view('admin.origenes-turno.form', compact('origenTurno'));
    }

    public function update(Request $request, OrigenTurno $origenTurno): RedirectResponse
    {
        $origenTurno->update($this->validar($request, $origenTurno));

        return redirect()->route('admin.origenes-turno.index')->with('status', 'Origen de turno actualizado.');
    }

    public function desactivar(OrigenTurno $origenTurno): RedirectResponse
    {
        $origenTurno->desactivar();

        return redirect()->route('admin.origenes-turno.index')->with('status', 'Origen de turno desactivado.');
    }

    private function validar(Request $request, ?OrigenTurno $origenTurno = null): array
    {
        $reglas = [
            'codigo' => ['required', 'string', 'max:20', Rule::unique('origenes_turno')->ignore($origenTurno)],
            'nombre' => ['required', 'string', 'max:50'],
        ];

        if ($origenTurno) {
            $reglas['estado_id'] = OrigenTurno::reglaEstado();
        }

        return $request->validate($reglas, [], ['codigo' => 'código']);
    }
}
