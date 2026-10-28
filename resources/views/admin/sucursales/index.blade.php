@use('App\Support\Permisos')

<x-admin.page title="Sucursales" :create-route="Permisos::url('admin.sucursales.create')" create-label="Nueva sucursal">
    @if (Permisos::puedeRuta('admin.clinica.edit'))
        <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
            La razón social, el RUC y la dirección fiscal de las facturas son los de la clínica; la dirección y el teléfono de cada sucursal,
            los del establecimiento. <a href="{{ route('admin.clinica.edit') }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Datos de la clínica</a>
        </p>
    @endif
    <x-admin.listado :action="route('admin.sucursales.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, dirección o teléfono…">
        @include('admin.sucursales._tabla')
    </x-admin.listado>
</x-admin.page>
