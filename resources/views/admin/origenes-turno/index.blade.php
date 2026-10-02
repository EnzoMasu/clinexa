@use('App\Support\Permisos')

<x-admin.page title="Orígenes de turno" :create-route="Permisos::url('admin.origenes-turno.create')" create-label="Nuevo origen">
    <x-admin.listado :action="route('admin.origenes-turno.index')" :busqueda="$busqueda" placeholder="Buscar por código o nombre…">
        @include('admin.origenes-turno._tabla')
    </x-admin.listado>
</x-admin.page>
