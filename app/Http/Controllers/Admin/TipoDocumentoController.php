<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipoDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TipoDocumentoController extends Controller
{
    public function index(): View
    {
        return view('admin.tipos-documento.index', [
            'tiposDocumento' => TipoDocumento::orderBy('codigo')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.tipos-documento.form', ['tipoDocumento' => new TipoDocumento]);
    }

    public function store(Request $request): RedirectResponse
    {
        TipoDocumento::create($this->validar($request));

        return redirect()->route('admin.tipos-documento.index')->with('status', 'Tipo de documento creado.');
    }

    public function edit(TipoDocumento $tipoDocumento): View
    {
        return view('admin.tipos-documento.form', compact('tipoDocumento'));
    }

    public function update(Request $request, TipoDocumento $tipoDocumento): RedirectResponse
    {
        $tipoDocumento->update($this->validar($request, $tipoDocumento));

        return redirect()->route('admin.tipos-documento.index')->with('status', 'Tipo de documento actualizado.');
    }

    public function desactivar(TipoDocumento $tipoDocumento): RedirectResponse
    {
        $tipoDocumento->update(['estado' => 'INACTIVO']);

        return redirect()->route('admin.tipos-documento.index')->with('status', 'Tipo de documento desactivado.');
    }

    private function validar(Request $request, ?TipoDocumento $tipoDocumento = null): array
    {
        $reglas = [
            'codigo' => ['required', 'string', 'max:10', Rule::unique('tipos_documento')->ignore($tipoDocumento)],
            'nombre' => ['required', 'string', 'max:50'],
            'aplica_a' => ['required', Rule::in(['FISICA', 'JURIDICA', 'AMBOS'])],
        ];

        if ($tipoDocumento) {
            $reglas['estado'] = ['required', Rule::in(['ACTIVO', 'INACTIVO'])];
        }

        return $request->validate($reglas);
    }
}
