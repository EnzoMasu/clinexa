@use('App\Support\Permisos')

<x-admin.page title="Especialidades" :create-route="Permisos::url('admin.especialidades.create')" create-label="Nueva especialidad">
    <x-admin.listado :action="route('admin.especialidades.index')" :busqueda="$busqueda" placeholder="Buscar por nombre o descripción…">
        @include('admin.especialidades._tabla')
    </x-admin.listado>
</x-admin.page>
