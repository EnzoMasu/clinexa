<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MedioPago;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MedioPagoController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.medios-pago', [
            'mediosPago' => $this->buscarEn(MedioPago::query(), $busqueda, ['nombre'])
                ->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
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
        $medioPago->update($this->validar($request, $medioPago));

        return redirect()->route('admin.medios-pago.index')->with('status', 'Medio de pago actualizado.');
    }

    public function desactivar(MedioPago $medioPago): RedirectResponse
    {
        $medioPago->desactivar();

        return redirect()->route('admin.medios-pago.index')->with('status', 'Medio de pago desactivado.');
    }

    private function validar(Request $request, ?MedioPago $medioPago = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:50', Rule::unique('medios_pago')->ignore($medioPago)],
        ];

        if ($medioPago) {
            $reglas['estado_id'] = MedioPago::reglaEstado();
        }

        return $request->validate($reglas);
    }
}
