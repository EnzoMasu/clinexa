@use('App\Support\Permisos')

<x-admin.page title="Consultorios" :create-route="Permisos::url('admin.consultorios.create')" create-label="Nuevo consultorio">
    <x-admin.listado :action="route('admin.consultorios.index')" :busqueda="$busqueda" placeholder="Buscar por consultorio o sucursal…">
        @include('admin.consultorios._tabla')
    </x-admin.listado>
</x-admin.page>
