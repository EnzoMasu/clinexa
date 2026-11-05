{{-- Tasas de IVA de los procedimientos (TasaIvaController::index, docs/facturacion.md). Precios con IVA incluido; la tasa se copia a
     cada línea al facturar. --}}
<x-admin.page title="Facturas">
    @include('admin.facturas._pestanas', ['activa' => 'tasas'])

    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Las tarifas son con IVA incluido. IVA 10 % = monto ÷ 11 y 5 % = monto ÷ 21, redondeado al guaraní; la exenta no lleva IVA. Cambiar
        la tasa vale para las facturas nuevas: las emitidas no cambian.
    </p>

    <x-admin.listado :action="route('admin.facturas.tasas-iva')" :busqueda="$busqueda" placeholder="Buscar procedimiento…">
        @include('admin.facturas._tabla-tasas-iva')
    </x-admin.listado>
</x-admin.page>
