<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MedioPago;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MedioPagoController extends Controller
{
    public function index(): View
    {
        return view('admin.medios-pago.index', [
            'mediosPago' => MedioPago::orderBy('nombre')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.medios-pago.form', ['medioPago' => new MedioPago]);
    }

    public function store(Request $request): RedirectResponse
    {
        MedioPago::create($this->validar($request));

        return redirect()->route('admin.medios-pago.index')->with('status', 'Medio de pago creado.');
    }

    public function edit(MedioPago $medioPago): View
    {
        return view('admin.medios-pago.form', compact('medioPago'));
    }

    public function update(Request $request, MedioPago $medioPago): RedirectResponse
    {
        $medioPago->update($this->validar($request));

        return redirect()->route('admin.medios-pago.index')->with('status', 'Medio de pago actualizado.');
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:50'],
        ]);
    }
}
