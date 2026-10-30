{{-- Factura lista para imprimir (FacturaController::imprimir): solo lectura por GET, con el PDF embebido (facturas.pdf, que es el que
     deja el EXPORTAR en Auditoría y va sin almacenar). Requiere EXPORTAR sobre FACTURAS. Sin contenido clínico. --}}
<x-admin.page title="Factura {{ $factura->numero_completo }}">
    @include('admin.facturas._simulacion')
    <div class="space-y-4 p-6 text-sm" x-data="imprimirFactura({ url: @js(route('admin.facturas.pdf', $factura)) })">
        <div class="flex flex-wrap gap-3">
            <button type="button" x-on:click="imprimir()" class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 font-medium text-white hover:bg-indigo-500">Imprimir</button>
            @if ($veCobros)
                <a href="{{ route('admin.cobros.create') }}" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">Nuevo cobro</a>
            @endif
            <a href="{{ route('admin.facturas.show', $factura) }}" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">Ver detalle de la factura</a>
        </div>

        <iframe x-ref="pdf" src="{{ route('admin.facturas.pdf', $factura) }}" title="Factura {{ $factura->numero_completo }} (PDF)" class="h-[80vh] w-full rounded-md border border-gray-300 dark:border-gray-600"></iframe>
    </div>
</x-admin.page>
