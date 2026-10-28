<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MotivoAnulacionFactura;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Motivos de anulación de factura (docs/facturacion.md), una pestaña de Facturas: catálogo editable; nada se borra, se desactiva. */
class MotivoAnulacionController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.motivos-anulacion', [
            'motivos' => $this->buscarEn(MotivoAnulacionFactura::query(), $busqueda, ['nombre'])->orderBy('id')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.motivos-anulacion.form', ['motivo' => new MotivoAnulacionFactura]);
    }

    public function store(Request $request): RedirectResponse
    {
        MotivoAnulacionFactura::create($this->validar($request));

        return redirect()->route('admin.facturas.motivos')->with('status', 'Motivo de anulación creado.');
    }

    public function edit(MotivoAnulacionFactura $motivoAnulacion): View
    {
        return view('admin.motivos-anulacion.form', ['motivo' => $motivoAnulacion]);
    }

    public function update(Request $request, MotivoAnulacionFactura $motivoAnulacion): RedirectResponse
    {
        $motivoAnulacion->update($this->validar($request, $motivoAnulacion));

        return redirect()->route('admin.facturas.motivos')->with('status', 'Motivo de anulación actualizado.');
    }

    public function desactivar(MotivoAnulacionFactura $motivoAnulacion): RedirectResponse
    {
        $motivoAnulacion->desactivar();

        return redirect()->route('admin.facturas.motivos')->with('status', 'Motivo de anulación desactivado.');
    }

    private function validar(Request $request, ?MotivoAnulacionFactura $motivo = null): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('motivos_anulacion_factura')->ignore($motivo)],
            'requiere_detalle' => ['nullable', 'boolean'],
        ];
        if ($motivo) {
            $reglas['estado_id'] = MotivoAnulacionFactura::reglaEstado();
        }
        $datos = $request->validate($reglas, [], ['requiere_detalle' => 'detalle obligatorio']);
        $datos['requiere_detalle'] = $request->boolean('requiere_detalle');

        return $datos;
    }
}
