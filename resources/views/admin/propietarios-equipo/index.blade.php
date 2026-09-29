@use('App\Support\Permisos')

<x-admin.page title="Propietarios de equipo" :create-route="Permisos::url('admin.propietarios-equipo.create')" create-label="Nuevo propietario">
    <x-admin.listado :action="route('admin.propietarios-equipo.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, razón social o documento…">
        @include('admin.propietarios-equipo._tabla')
    </x-admin.listado>
</x-admin.page>
