@use('App\Support\Permisos')

{{-- Tarifas (TarifaController::index, docs/tarifas-prestaciones.md): las vigentes o, con el filtro, también las históricas. --}}
<x-admin.page title="Tarifas" :create-route="Permisos::url('admin.tarifas.create')" create-label="Nueva tarifa">
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Una tarifa no se edita: "Cambiar tarifa" cierra la vigente y abre otra desde la fecha que indique. Al finalizar una consulta se
        usa la tarifa del profesional y, si no tiene, la general del procedimiento. Las prestaciones ya tarifadas no cambian.
    </p>
    <x-admin.listado :action="route('admin.tarifas.index')" :busqueda="$busqueda" placeholder="Buscar por procedimiento o profesional…">
        <x-slot name="filtros">
            <div class="w-48"><x-admin.select name="estado" label="Mostrar" :options="['VIGENTES' => 'Solo vigentes', 'TODAS' => 'Vigentes e históricas']" :value="$filtros['estado']" /></div>
        </x-slot>

        @include('admin.tarifas._tabla')
    </x-admin.listado>
</x-admin.page>
