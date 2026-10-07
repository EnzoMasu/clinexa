@use('App\Support\Permisos')

<x-admin.page title="Ciudades" :create-route="Permisos::url('admin.ciudades.create')" create-label="Nueva ciudad">
    <x-admin.listado :action="route('admin.ciudades.index')" :busqueda="$busqueda" placeholder="Buscar por ciudad, departamento o país…">
        @include('admin.ciudades._tabla')
    </x-admin.listado>
</x-admin.page>
