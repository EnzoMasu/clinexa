{{-- Facturas (FacturaController::index, docs/facturacion.md). Se emiten al cobrar, en Caja. Sin contenido clínico. --}}
<x-admin.page title="Facturas">
    @include('admin.facturas._pestanas', ['activa' => 'facturas'])

    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Cada cobro en Caja emite su factura contado, con solo lo que paga la paciente. Una factura no se edita: si hace falta, se anula
        desde su detalle (el cobro se revierte) y su número no se vuelve a usar.
    </p>

    <x-admin.listado :action="route('admin.facturas.index')" :busqueda="$busqueda" placeholder="Buscar por número, cliente o documento…">
        <x-slot name="filtros">
            <div class="w-40"><x-admin.fecha name="desde" label="Desde" :value="$filtros['desde']" /></div>
            <div class="w-40"><x-admin.fecha name="hasta" label="Hasta" :value="$filtros['hasta']" /></div>
            <div class="w-48"><x-admin.select name="estado" label="Estado" :options="App\Models\Factura::ETIQUETAS" :value="$filtros['estado']" nullable /></div>
        </x-slot>

        @include('admin.facturas._tabla')
    </x-admin.listado>
</x-admin.page>
