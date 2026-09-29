@use('App\Support\Permisos')

<x-admin.page title="Responsables de pago" :create-route="Permisos::url('admin.responsables-pago.create')" create-label="Nuevo responsable">
    <x-admin.listado :action="route('admin.responsables-pago.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, razón social o documento…">
        @include('admin.responsables-pago._tabla')
    </x-admin.listado>
</x-admin.page>
