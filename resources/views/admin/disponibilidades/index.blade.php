@use('App\Support\Permisos')

<x-admin.page title="Disponibilidades" :create-route="Permisos::url('admin.disponibilidades.create')" create-label="Nueva disponibilidad">
    <x-admin.listado :action="route('admin.disponibilidades.index')" :busqueda="$busqueda" placeholder="Buscar por profesional o consultorio…">
        @include('admin.disponibilidades._tabla')
    </x-admin.listado>
</x-admin.page>
