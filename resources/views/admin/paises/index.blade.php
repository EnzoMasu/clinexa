@use('App\Support\Permisos')

<x-admin.page title="Países" :create-route="Permisos::url('admin.paises.create')" create-label="Nuevo país">
    <x-admin.listado :action="route('admin.paises.index')" :busqueda="$busqueda" placeholder="Buscar por nombre o código…">
        @include('admin.paises._tabla')
    </x-admin.listado>
</x-admin.page>
