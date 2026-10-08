<x-admin.page title="Historias clínicas">
    <x-admin.listado :action="route('admin.historias-clinicas.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, documento o ficha…">
        @include('admin.historias-clinicas._tabla')
    </x-admin.listado>
</x-admin.page>
