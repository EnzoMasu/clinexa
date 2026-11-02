@use('App\Support\Permisos')

<x-admin.page title="Tipos de comprobante de compra" :create-route="Permisos::url('admin.tipos-comprobante-compra.create')" create-label="Nuevo tipo">
    <x-admin.listado :action="route('admin.tipos-comprobante-compra.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.tipos-comprobante-compra._tabla')
    </x-admin.listado>
</x-admin.page>
