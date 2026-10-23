@use('App\Support\Permisos')

{{-- Convenios y planes (ConvenioController::index, docs/convenios-seguros.md): los vigentes o, con el filtro, también los históricos. --}}
<x-admin.page title="Convenios" :create-route="Permisos::url('admin.convenios.create')" create-label="Nuevo convenio">
    @include('admin.convenios._pestanas')
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Cada convenio es con un responsable de pago (la aseguradora) y tiene sus planes, que son solo un nombre: qué cubre y cuánto paga
        la paciente lo decide la aseguradora en cada visación, que Recepción carga desde la ficha de la paciente o el turno.
    </p>
    <x-admin.listado :action="route('admin.convenios.index')" :busqueda="$busqueda" placeholder="Buscar por aseguradora o convenio…">
        <x-slot name="filtros">
            <div class="w-48"><x-admin.select name="estado" label="Mostrar" :options="['VIGENTES' => 'Solo vigentes', 'TODOS' => 'Vigentes e históricos']" :value="$filtros['estado']" /></div>
        </x-slot>

        @include('admin.convenios._tabla')
    </x-admin.listado>
</x-admin.page>
