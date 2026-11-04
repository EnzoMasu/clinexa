{{-- Timbrados (TimbradoController::index, docs/facturacion.md): el vigente de cada punto de expedición y los históricos. Nada se borra
     ni se edita: cargar otro para el mismo punto pasa el anterior a histórico. --}}
<x-admin.page title="Facturas" :create-route="$puedeCargar ? route('admin.facturas.timbrados.create') : null" create-label="Cargar timbrado">
    @include('admin.facturas._pestanas', ['activa' => 'timbrados'])

    @if ($aviso)
        <p role="alert" class="mx-4 mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ $aviso }}</p>
    @endif
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Se emite con el timbrado vigente de la sucursal principal (un solo punto de expedición). La numeración la asigna el sistema, de a
        uno, sin saltos; un número anulado no se vuelve a usar. Los datos fiscales del emisor salen de los
        <a href="{{ route('admin.clinica.edit') }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">datos de la clínica</a>.
    </p>

    <x-admin.listado :action="route('admin.facturas.timbrados')" :buscador="false">
        @include('admin.facturas._tabla-timbrados')
    </x-admin.listado>
</x-admin.page>
