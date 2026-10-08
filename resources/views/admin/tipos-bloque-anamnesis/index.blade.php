@use('App\Support\Permisos')

<x-admin.page title="Tipos de bloque de anamnesis" :create-route="Permisos::url('admin.tipos-bloque-anamnesis.create')" create-label="Nuevo tipo">
    <x-admin.listado :action="route('admin.tipos-bloque-anamnesis.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.tipos-bloque-anamnesis._tabla')
    </x-admin.listado>
</x-admin.page>
