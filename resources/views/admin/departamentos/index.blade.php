@use('App\Support\Permisos')

<x-admin.page title="Departamentos" :create-route="Permisos::url('admin.departamentos.create')" create-label="Nuevo departamento">
    <x-admin.listado :action="route('admin.departamentos.index')" :busqueda="$busqueda" placeholder="Buscar por departamento o país…">
        @include('admin.departamentos._tabla')
    </x-admin.listado>
</x-admin.page>
