<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Procedimiento;
use App\Support\Auditoria;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tasas de IVA de los procedimientos (docs/facturacion.md), una pestaña de Facturas: el listado (VER sobre DATOS_FISCALES) y el cambio
 * de la tasa de uno (EDITAR: Gerencia y el Administrador). Precios con IVA incluido; la tasa se copia a cada línea al facturar: las
 * facturas ya emitidas no cambian. En Auditoría, EDITAR del procedimiento.
 */
class TasaIvaController extends Controller
{
    public function index(Request $request): View
    {
        $busqueda = trim((string) $request->query('q'));

        return view('admin.facturas.tasas-iva', [
            'procedimientos' => $this->buscarEn(Procedimiento::query(), $busqueda, ['codigo', 'nombre'])->orderBy('nombre')->paginate(30)->withQueryString(),
            'busqueda' => $busqueda,
            'puedeEditar' => Permisos::puedeRuta('admin.facturas.tasas-iva.update'),
        ]);
    }

    public function update(Request $request, Procedimiento $procedimiento): RedirectResponse
    {
        $tasa = (int) $request->validate(['tasa_iva' => ['required', 'integer', Rule::in(array_keys(Procedimiento::TASAS_IVA))]], [], ['tasa_iva' => 'tasa de IVA'])['tasa_iva'];
        if ($tasa === $procedimiento->tasa_iva) {
            return back()->with('status', 'La tasa no cambió.');
        }
        Auditoria::conDetalle('Cambio de la tasa de IVA', fn () => $procedimiento->update(['tasa_iva' => $tasa]));

        return back()->with('status', "Tasa de IVA de {$procedimiento->nombre}: ".Procedimiento::TASAS_IVA[$tasa].'. Las facturas ya emitidas no cambian.');
    }
}
