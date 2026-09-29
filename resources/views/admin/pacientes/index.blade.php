@use('App\Support\Permisos')

<x-admin.page title="Pacientes" :create-route="Permisos::url('admin.pacientes.create')" create-label="Nuevo paciente">
    <x-admin.listado :action="route('admin.pacientes.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, documento o nro. de ficha…">
        @include('admin.pacientes._tabla')
    </x-admin.listado>
</x-admin.page>
