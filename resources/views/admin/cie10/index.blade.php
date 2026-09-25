@use('App\Support\Permisos')

<x-admin.page title="Catálogo CIE-10" :create-route="Permisos::url('admin.cie10.create')" create-label="Nuevo código">
    <x-admin.listado :action="route('admin.cie10.index')" :busqueda="$busqueda" placeholder="Buscar por código o descripción…">
        @include('admin.cie10._tabla')
    </x-admin.listado>
</x-admin.page>
