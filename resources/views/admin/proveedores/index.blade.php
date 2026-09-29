@use('App\Support\Permisos')

<x-admin.page title="Proveedores" :create-route="Permisos::url('admin.proveedores.create')" create-label="Nuevo proveedor">
    <x-admin.listado :action="route('admin.proveedores.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, razón social o documento…">
        @include('admin.proveedores._tabla')
    </x-admin.listado>
</x-admin.page>
