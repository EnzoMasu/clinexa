@use('App\Support\Permisos')

{{-- Tarifas del convenio (ConvenioController::tarifas, docs/convenios-seguros.md): las vigentes o también las históricas. --}}
<x-admin.page title="Convenios" :create-route="Permisos::url('admin.convenios.tarifas.create')" create-label="Cargar o cambiar tarifa">
    @include('admin.convenios._pestanas')
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Una tarifa del convenio no se edita: al cargar otra para el mismo procedimiento, la vigente se cierra y se abre la nueva. Con una
        visación aprobada se usa la tarifa del convenio; si el procedimiento no figura, la tarifa general.
    </p>
    <x-admin.listado :action="route('admin.convenios.tarifas')" :busqueda="$busqueda" placeholder="Buscar por procedimiento…">
        <x-slot name="filtros">
            <div class="w-64"><x-admin.select name="convenio" label="Convenio" :options="$opcionesConvenio" :value="$filtros['convenio']" nullable /></div>
            <div class="w-48"><x-admin.select name="estado" label="Mostrar" :options="['VIGENTES' => 'Solo vigentes', 'TODAS' => 'Vigentes e históricas']" :value="$filtros['estado']" /></div>
        </x-slot>

        @include('admin.convenios.tarifas._tabla')
    </x-admin.listado>
</x-admin.page>
