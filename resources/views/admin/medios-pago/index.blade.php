@use('App\Support\Permisos')

<x-admin.page title="Medios de pago" :create-route="Permisos::url('admin.medios-pago.create')" create-label="Nuevo medio de pago">
    <x-admin.listado :action="route('admin.medios-pago.index')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.medios-pago._tabla')
    </x-admin.listado>
</x-admin.page>
