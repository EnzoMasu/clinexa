@use('App\Support\Permisos')

<x-admin.page title="Compras y gastos" :create-route="Permisos::url('admin.compras.create')" create-label="Nueva compra">
    <x-admin.listado :action="route('admin.compras.index')" :busqueda="$busqueda" placeholder="Buscar por número de comprobante…">
        @include('admin.compras._tabla')
    </x-admin.listado>
</x-admin.page>
