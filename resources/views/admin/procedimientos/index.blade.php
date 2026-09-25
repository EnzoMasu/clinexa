@use('App\Support\Permisos')

<x-admin.page title="Procedimientos" :create-route="Permisos::url('admin.procedimientos.create')" create-label="Nuevo procedimiento">
    <x-admin.listado :action="route('admin.procedimientos.index')" :busqueda="$busqueda" placeholder="Buscar por código, nombre o tipo…">
        @include('admin.procedimientos._tabla')
    </x-admin.listado>
</x-admin.page>
