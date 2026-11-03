<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CondicionCompra;
use App\Exceptions\CompraDuplicada;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompraRequest;
use App\Models\CategoriaGasto;
use App\Models\Compra;
use App\Models\Moneda;
use App\Models\Proveedor;
use App\Models\TipoComprobanteCompra;
use App\Support\Compras\RegistrarCompra;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Compras y gastos (docs/compras-gastos.md): alta y edición del borrador, y el detalle. Solo se eligen proveedores, categorías y tipos
 * de comprobante activos; las compras antiguas conservan los suyos. El comprobante repetido se rechaza con un enlace a la compra que
 * ya lo tiene. Nada se borra.
 */
class CompraController extends Controller
{
    public function create(): View
    {
        return view('admin.compras.create', ['compra' => new Compra(['condicion' => CondicionCompra::CONTADO]), ...$this->opciones()]);
    }

    public function store(CompraRequest $request): RedirectResponse
    {
        try {
            $compra = RegistrarCompra::registrar($request->user(), $request->datosCompra());
        } catch (CompraDuplicada $e) {
            return $this->rechazoPorDuplicado($e);
        }

        return redirect()->route('admin.compras.show', $compra)->with('status', 'Compra registrada como borrador.');
    }

    public function show(Compra $compra): View
    {
        $compra->load(['proveedor.persona', 'categoriaGasto', 'tipoComprobante', 'moneda', 'estado', 'registradaPor.persona', 'confirmadaPor.persona', 'anuladaPor.persona']);

        return view('admin.compras.show', ['compra' => $compra, 'puedeEditar' => Gate::allows('update', $compra) && request()->user()->tienePermiso('COMPRAS', 'EDITAR')]);
    }

    public function edit(Compra $compra): View
    {
        Gate::authorize('update', $compra);

        return view('admin.compras.edit', ['compra' => $compra, ...$this->opciones()]);
    }

    public function update(CompraRequest $request, Compra $compra): RedirectResponse
    {
        Gate::authorize('update', $compra);
        try {
            RegistrarCompra::actualizar($compra, $request->datosCompra());
        } catch (CompraDuplicada $e) {
            return $this->rechazoPorDuplicado($e);
        }

        return redirect()->route('admin.compras.show', $compra)->with('status', 'Compra actualizada.');
    }

    /** Vuelve al formulario con lo escrito; la vista enlaza a la compra que ya tiene ese comprobante. */
    private function rechazoPorDuplicado(CompraDuplicada $e): RedirectResponse
    {
        return back()->withInput()->withErrors(['numero_comprobante' => 'Ese comprobante ya está registrado para este proveedor.'])
            ->with('compra_existente', $e->existente->id);
    }

    /** Lo que se puede elegir en el formulario: solo activos. Las casillas de cada tipo las usa compra-formulario.js. */
    private function opciones(): array
    {
        $proveedores = Proveedor::activos()->with('persona')->get()
            ->mapWithKeys(fn (Proveedor $proveedor) => [$proveedor->id => $proveedor->persona->nombre_completo])->sort();
        $tipos = TipoComprobanteCompra::activos()->orderBy('nombre')->get();

        return [
            'proveedores' => $proveedores->all(),
            'categorias' => CategoriaGasto::activos()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'tipos' => $tipos->pluck('nombre', 'id')->all(),
            'casillasPorTipo' => $tipos->mapWithKeys(fn (TipoComprobanteCompra $tipo) => [$tipo->id => $tipo->only(array_keys(TipoComprobanteCompra::CASILLAS))])->all(),
            'condiciones' => collect(CondicionCompra::cases())->mapWithKeys(fn (CondicionCompra $condicion) => [$condicion->value => $condicion->etiqueta()])->all(),
            'moneda' => Moneda::guarani(),
        ];
    }
}
