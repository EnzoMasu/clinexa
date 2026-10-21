@use('App\Support\Permisos')

<x-admin.page title="Tipos de equipo" :create-route="Permisos::url('admin.tipos-equipo.create')" create-label="Nuevo tipo">
    <x-admin.listado :action="route('admin.tipos-equipo.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.tipos-equipo._tabla')
    </x-admin.listado>
</x-admin.page>
