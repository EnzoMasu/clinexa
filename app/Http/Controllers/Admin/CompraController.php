<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CondicionCompra;
use App\Exceptions\AccionRechazada;
use App\Exceptions\CompraDuplicada;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompraRequest;
use App\Models\CategoriaGasto;
use App\Models\Compra;
use App\Models\Estado;
use App\Models\Moneda;
use App\Models\Proveedor;
use App\Models\TipoComprobanteCompra;
use App\Support\Compras\FlujoCompra;
use App\Support\Compras\RegistrarCompra;
use App\Support\Grilla\Grilla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Compras y gastos (docs/compras-gastos.md): listado, alta y edición del borrador, detalle, confirmar y anular. Solo se eligen proveedores, categorías y tipos
 * de comprobante activos; las compras antiguas conservan los suyos. El comprobante repetido se rechaza con un enlace a la compra que
 * ya lo tiene. Nada se borra.
 */
class CompraController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q'));
        $grilla = Grilla::de($request)
            ->numero('numero', 'N.º', 'compras.id')
            ->fecha('fecha', 'Fecha', 'compras.fecha')
            ->persona('proveedor', 'Proveedor', 'compras.proveedor_id', 'proveedores')
            // Todas las categorías, también las inactivas: las compras antiguas conservan la suya.
            ->opciones('categoria', 'Categoría de gasto', 'compras.categoria_gasto_id', CategoriaGasto::orderBy('nombre')->pluck('nombre', 'id')->all(),
                orden: fn ($query) => $query->select('nombre')->from('categorias_gasto')->whereColumn('categorias_gasto.id', 'compras.categoria_gasto_id'))
            ->texto('comprobante', 'Comprobante', 'compras.numero_comprobante')
            ->opciones('condicion', 'Condición', 'compras.condicion', collect(CondicionCompra::cases())->mapWithKeys(fn (CondicionCompra $c) => [$c->value => $c->etiqueta()])->all())
            ->numero('total', 'Total', 'compras.total')
            ->estado('estado', 'Estado', 'compras.estado_id', Compra::ETIQUETAS);

        return $this->listado($request, 'admin.compras', [
            'registros' => $grilla->aplicar(
                $this->buscarEn(Compra::query()->with(['proveedor.persona', 'categoriaGasto', 'tipoComprobante', 'moneda']), $busqueda, ['compras.numero_comprobante']),
                fn ($query) => $query->orderByDesc('compras.fecha')->orderByDesc('compras.id'),
            )->paginate(15)->withQueryString(),
            'grilla' => $grilla,
            'busqueda' => $busqueda,
        ]);
    }

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

        return view('admin.compras.show', ['compra' => $compra, 'puedeEditar' => Gate::allows('update', $compra) && request()->user()->tienePermiso('COMPRAS', 'EDITAR'),
            'puedeConfirmar' => $compra->esBorrador() && request()->user()->tienePermiso('CONFIRMACION_COMPRAS', 'EDITAR') && Gate::allows('confirmar', $compra),
            'puedeAnular' => ! $compra->tieneEstado(Estado::ANULADO) && Gate::allows('anular', $compra)]);
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

    public function confirmar(Request $request, Compra $compra): RedirectResponse
    {
        try {
            FlujoCompra::confirmar($request->user(), $compra, fn (Compra $actual) => Gate::authorize('confirmar', $actual));
        } catch (AccionRechazada $e) {
            return redirect()->route('admin.compras.show', $compra)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.compras.show', $compra)->with('status', 'Compra confirmada.');
    }

    /** Anular una confirmada o descartar un borrador: motivo obligatorio. Nada se borra. */
    public function anular(Request $request, Compra $compra): RedirectResponse
    {
        $motivo = $request->validate(
            ['motivo' => ['required', 'string', 'min:'.FlujoCompra::MOTIVO_MINIMO, 'max:'.FlujoCompra::MOTIVO_MAXIMO]],
            ['motivo.required' => 'Indique el motivo de la anulación.', 'motivo.min' => 'El motivo de la anulación debe tener al menos '.FlujoCompra::MOTIVO_MINIMO.' caracteres.'],
            ['motivo' => 'motivo de la anulación'],
        )['motivo'];

        try {
            $anulada = FlujoCompra::anular($request->user(), $compra, $motivo, fn (Compra $actual) => Gate::authorize('anular', $actual));
        } catch (AccionRechazada $e) {
            return redirect()->route('admin.compras.show', $compra)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.compras.show', $compra)->with('status', 'Compra anulada.');
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
