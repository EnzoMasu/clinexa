@use('App\Support\Permisos')

<x-admin.page title="Perfiles de acceso" :create-route="Permisos::url('admin.perfiles-acceso.create')" create-label="Nuevo perfil">
    <x-admin.listado :action="route('admin.perfiles-acceso.index')" :busqueda="$busqueda" placeholder="Buscar por nombre o descripción…">
        @include('admin.perfiles-acceso._tabla')
    </x-admin.listado>
</x-admin.page>
