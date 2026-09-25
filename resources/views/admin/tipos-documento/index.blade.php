@use('App\Support\Permisos')

<x-admin.page title="Tipos de documento" :create-route="Permisos::url('admin.tipos-documento.create')" create-label="Nuevo tipo">
    <x-admin.listado :action="route('admin.tipos-documento.index')" :busqueda="$busqueda" placeholder="Buscar por código, nombre o a quién aplica…">
        @include('admin.tipos-documento._tabla')
    </x-admin.listado>
</x-admin.page>
