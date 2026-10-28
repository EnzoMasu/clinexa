@use('App\Support\Permisos')

{{-- Motivos de anulación de factura (MotivoAnulacionController, docs/facturacion.md): catálogo editable; nada se borra, se desactiva. --}}
<x-admin.page title="Facturas" :create-route="Permisos::url('admin.facturas.motivos.create')" create-label="Nuevo motivo">
    @include('admin.facturas._pestanas', ['activa' => 'motivos'])
    <x-admin.listado :action="route('admin.facturas.motivos')" :busqueda="$busqueda" placeholder="Buscar por nombre…">
        @include('admin.motivos-anulacion._tabla')
    </x-admin.listado>
</x-admin.page>
