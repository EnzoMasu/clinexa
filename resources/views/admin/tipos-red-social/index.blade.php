@use('App\Support\Permisos')

<x-admin.page title="Tipos de red social" :create-route="Permisos::url('admin.tipos-red-social.create')" create-label="Nuevo tipo">
    <x-admin.listado :action="route('admin.tipos-red-social.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.tipos-red-social._tabla')
    </x-admin.listado>
</x-admin.page>
