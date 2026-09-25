@use('App\Support\Permisos')

<x-admin.page title="Categorías de gasto" :create-route="Permisos::url('admin.categorias-gasto.create')" create-label="Nueva categoría">
    <x-admin.listado :action="route('admin.categorias-gasto.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.categorias-gasto._tabla')
    </x-admin.listado>
</x-admin.page>
