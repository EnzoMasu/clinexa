@use('App\Support\Permisos')

<x-admin.page title="Categorías de proveedor" :create-route="Permisos::url('admin.categorias-proveedor.create')" create-label="Nueva categoría">
    <x-admin.listado :action="route('admin.categorias-proveedor.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.categorias-proveedor._tabla')
    </x-admin.listado>
</x-admin.page>
