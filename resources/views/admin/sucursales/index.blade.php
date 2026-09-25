@use('App\Support\Permisos')

<x-admin.page title="Sucursales" :create-route="Permisos::url('admin.sucursales.create')" create-label="Nueva sucursal">
    <x-admin.listado :action="route('admin.sucursales.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, dirección o teléfono…">
        @include('admin.sucursales._tabla')
    </x-admin.listado>
</x-admin.page>
