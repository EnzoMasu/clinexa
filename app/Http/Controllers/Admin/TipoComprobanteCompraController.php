<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TipoComprobanteCompraRequest;
use App\Models\TipoComprobanteCompra;
use App\Support\Grilla\Grilla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
        $siNo = ['1' => 'Sí', '0' => 'No'];
        $casilla = fn (string $columna) => fn ($query, $valor) => $query->where($columna, $valor === '1');
        $grilla = Grilla::de($request)
            ->texto('nombre', 'Nombre', 'nombre')
            // Número: no, sí, o sí con el formato legal (001-001-0000001).
            ->opciones('numero', 'Número', 'requiere_numero', ['2' => 'Sí (formato legal)', '1' => 'Sí', '0' => 'No'],
                orden: DB::raw('CASE WHEN requiere_numero THEN (CASE WHEN numero_formato_legal THEN 2 ELSE 1 END) ELSE 0 END'),
                filtro: fn ($query, $valor) => match ($valor) {
                    '2' => $query->where('requiere_numero', true)->where('numero_formato_legal', true),
                    '1' => $query->where('requiere_numero', true)->where('numero_formato_legal', false),
                    default => $query->where('requiere_numero', false),
                })
            ->opciones('timbrado', 'Timbrado', 'requiere_timbrado', $siNo, filtro: $casilla('requiere_timbrado'))
            ->opciones('fecha', 'Fecha del comprobante', 'requiere_fecha_comprobante', $siNo, filtro: $casilla('requiere_fecha_comprobante'))
            ->opciones('iva', 'Discrimina IVA', 'discrimina_iva', $siNo, filtro: $casilla('discrimina_iva'))
            ->estado('estado', 'Estado', 'estado_id', TipoComprobanteCompra::class);

        return $this->listado($request, 'admin.tipos-comprobante-compra', [
            'tipos' => $grilla->aplicar($this->buscarEn(TipoComprobanteCompra::query(), $busqueda, ['nombre']), fn ($query) => $query->orderBy('nombre'))
                ->paginate(15)->withQueryString(),
            'grilla' => $grilla,
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
