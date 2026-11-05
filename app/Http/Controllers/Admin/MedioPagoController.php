<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MedioPago;
use App\Support\Grilla\Grilla;
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
        $grilla = Grilla::de($request)
            ->texto('nombre', 'Nombre', 'nombre')
            ->texto('comprobante', 'Comprobante', 'nombre_comprobante')
            ->estado('estado', 'Estado', 'estado_id', MedioPago::class);

        return $this->listado($request, 'admin.medios-pago', [
            'mediosPago' => $grilla->aplicar($this->buscarEn(MedioPago::query(), $busqueda, ['nombre']), fn ($query) => $query->orderBy('nombre'))
                ->paginate(15)->withQueryString(),
            'grilla' => $grilla,
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
            'requiere_comprobante' => ['nullable', 'boolean'],
            'nombre_comprobante' => ['nullable', 'string', 'max:40', 'required_if_accepted:requiere_comprobante'],
        ];

        if ($medioPago) {
            $reglas['estado_id'] = MedioPago::reglaEstado();
        }

        $datos = $request->validate($reglas, ['nombre_comprobante.required_if_accepted' => 'Indique cómo se llama el comprobante (por ejemplo, «Voucher del POS»).'], ['nombre_comprobante' => 'nombre del comprobante']);
        // El efectivo nunca pide comprobante.
        $datos['requiere_comprobante'] = $request->boolean('requiere_comprobante') && ! $medioPago?->esEfectivo();
        $datos['nombre_comprobante'] = trim((string) ($datos['nombre_comprobante'] ?? '')) ?: null;

        return $datos;
    }
}
