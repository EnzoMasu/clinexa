@use('App\Support\Permisos')

<x-admin.page title="Historias clínicas">
    {{-- Ayuda: esta pantalla es para revisar historias; la atención del día está en Consulta. --}}
    <p class="px-4 pt-4 text-xs text-gray-500 dark:text-gray-400">
        Aquí se revisan las historias. La cantidad de consultas y la última cuentan solo las finalizadas.
        @if ($urlConsulta = Permisos::url('admin.atencion.index'))
            Para atender a los pacientes de hoy use <a href="{{ $urlConsulta }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Consulta</a>.
        @endif
    </p>
    <x-admin.listado :action="route('admin.historias-clinicas.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, documento o ficha…">
        @include('admin.historias-clinicas._tabla')
    </x-admin.listado>
</x-admin.page>
