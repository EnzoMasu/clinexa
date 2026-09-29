@use('App\Support\Permisos')

<x-admin.page title="Profesionales" :create-route="Permisos::url('admin.profesionales.create')" create-label="Nuevo profesional">
    <x-admin.listado :action="route('admin.profesionales.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, documento o matrícula…">
        @include('admin.profesionales._tabla')
    </x-admin.listado>
</x-admin.page>
