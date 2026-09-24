<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Procedimiento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProcedimientoController extends Controller
{
    public function index(): View
    {
        return view('admin.procedimientos.index', [
            'procedimientos' => Procedimiento::orderBy('codigo')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.procedimientos.form', ['procedimiento' => new Procedimiento]);
    }

    public function store(Request $request): RedirectResponse
    {
        Procedimiento::create($this->validar($request));

        return redirect()->route('admin.procedimientos.index')->with('status', 'Procedimiento creado.');
    }

    public function edit(Procedimiento $procedimiento): View
    {
        return view('admin.procedimientos.form', compact('procedimiento'));
    }

    public function update(Request $request, Procedimiento $procedimiento): RedirectResponse
    {
        $procedimiento->update($this->validar($request, $procedimiento));

        return redirect()->route('admin.procedimientos.index')->with('status', 'Procedimiento actualizado.');
    }

    public function desactivar(Procedimiento $procedimiento): RedirectResponse
    {
        $procedimiento->update(['estado' => 'INACTIVO']);

        return redirect()->route('admin.procedimientos.index')->with('status', 'Procedimiento desactivado.');
    }

    private function validar(Request $request, ?Procedimiento $procedimiento = null): array
    {
        $reglas = [
            'codigo' => ['required', 'string', 'max:20', Rule::unique('procedimientos')->ignore($procedimiento)],
            'nombre' => ['required', 'string', 'max:150'],
            'tipo' => ['required', Rule::in(['CONSULTA', 'ESTUDIO'])],
            'duracion_estimada_minutos' => ['required', 'integer', 'min:1'],
        ];

        if ($procedimiento) {
            $reglas['estado'] = ['required', Rule::in(['ACTIVO', 'INACTIVO'])];
        }

        return $request->validate($reglas);
    }
}
