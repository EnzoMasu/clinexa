<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TipoComprobanteCompraRequest;
use App\Models\TipoComprobanteCompra;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Tipos de comprobante de compra (docs/compras-gastos.md): catálogo editable con sus casillas de reglas. Nada se borra: se desactiva,
 * y las compras que ya lo usan lo conservan.
 */
class TipoComprobanteCompraController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));

        return $this->listado($request, 'admin.tipos-comprobante-compra', [
            'tipos' => $this->buscarEn(TipoComprobanteCompra::query(), $busqueda, ['nombre'])->orderBy('nombre')->paginate(15)->withQueryString(),
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        return view('admin.tipos-comprobante-compra.form', ['tipo' => new TipoComprobanteCompra]);
    }

    public function store(TipoComprobanteCompraRequest $request): RedirectResponse
    {
        TipoComprobanteCompra::create($request->validated());

        return redirect()->route('admin.tipos-comprobante-compra.index')->with('status', 'Tipo de comprobante creado.');
    }

    public function edit(TipoComprobanteCompra $tipoComprobanteCompra): View
    {
        return view('admin.tipos-comprobante-compra.form', ['tipo' => $tipoComprobanteCompra]);
    }

    public function update(TipoComprobanteCompraRequest $request, TipoComprobanteCompra $tipoComprobanteCompra): RedirectResponse
    {
        $tipoComprobanteCompra->update($request->validated());

        return redirect()->route('admin.tipos-comprobante-compra.index')->with('status', 'Tipo de comprobante actualizado.');
    }

    public function desactivar(TipoComprobanteCompra $tipoComprobanteCompra): RedirectResponse
    {
        $tipoComprobanteCompra->desactivar();

        return redirect()->route('admin.tipos-comprobante-compra.index')->with('status', 'Tipo de comprobante desactivado.');
    }
}
