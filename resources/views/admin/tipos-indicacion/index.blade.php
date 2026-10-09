@use('App\Support\Permisos')

<x-admin.page title="Tipos de indicación" :create-route="Permisos::url('admin.tipos-indicacion.create')" create-label="Nuevo tipo">
    <x-admin.listado :action="route('admin.tipos-indicacion.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.tipos-indicacion._tabla')
    </x-admin.listado>
</x-admin.page>
