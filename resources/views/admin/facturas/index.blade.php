{{-- Facturas (FacturaController::index, docs/facturacion.md). Se emiten al cobrar, en Caja. Sin contenido clínico. --}}
<x-admin.page title="Facturas">
    @include('admin.facturas._pestanas', ['activa' => 'facturas'])

    <x-admin.listado :action="route('admin.facturas.index')" :busqueda="$busqueda" placeholder="Buscar por número, cliente o documento…">
        {{-- Fechas, estado y demás: en la fila de filtros de la grilla (docs/grillas.md). --}}
        @include('admin.facturas._tabla')
    </x-admin.listado>
</x-admin.page>
