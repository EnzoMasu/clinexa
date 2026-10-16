@use('App\Support\Permisos')

<x-admin.page title="Estudios" :create-route="Permisos::url('admin.estudios.create')" create-label="Nuevo estudio">
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        Los estudios activos se ofrecen en la orden de estudio. Si después edita o desactiva uno, las órdenes ya emitidas no cambian.
    </p>
    <x-admin.listado :action="route('admin.estudios.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.estudios._tabla')
    </x-admin.listado>
</x-admin.page>
