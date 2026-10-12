@use('App\Support\Permisos')

<x-admin.page title="Personal de salud" :create-route="Permisos::url('admin.personal-salud.create')" create-label="Nuevo personal de salud">
    <x-admin.listado :action="route('admin.personal-salud.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, documento o registro…">
        @include('admin.personal-salud._tabla')
    </x-admin.listado>
</x-admin.page>
