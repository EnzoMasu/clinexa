@use('App\Support\Permisos')

<x-admin.page title="Usuarios" :create-route="Permisos::url('admin.usuarios.create')" create-label="Nuevo usuario">
    <x-admin.listado :action="route('admin.usuarios.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, documento o email…">
        @include('admin.usuarios._tabla')
    </x-admin.listado>
</x-admin.page>
