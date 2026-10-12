@use('App\Support\Permisos')

<x-admin.page title="Cargos de salud" :create-route="Permisos::url('admin.cargos-salud.create')" create-label="Nuevo cargo">
    <x-admin.listado :action="route('admin.cargos-salud.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.cargos-salud._tabla')
    </x-admin.listado>
</x-admin.page>
