@use('App\Support\Permisos')

<x-admin.page title="Turnos" :create-route="Permisos::url('admin.turnos.create')" create-label="Nuevo turno">
    <x-admin.listado :action="route('admin.turnos.index')" :busqueda="$busqueda" placeholder="Buscar por paciente, profesional o fecha (dd/mm/aaaa)…">
        @include('admin.turnos._tabla')
    </x-admin.listado>
</x-admin.page>
