{{-- Cobros (CobroController::index, docs/cobros-caja.md). Sin contenido clínico. --}}
<x-admin.page title="Cobros" :create-route="$puedeCobrar ? route('admin.cobros.create') : null" create-label="Cobrar">
    @include('admin.cobros._pestanas', ['activa' => 'cobros'])

    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        La paciente paga después de finalizar la consulta, siempre el total de las prestaciones que elige. Un cobro no se edita: si hace
        falta, se revierte completo desde su detalle.
    </p>

    <x-admin.listado :action="route('admin.cobros.index')" :busqueda="$busqueda" placeholder="Buscar por paciente, documento o ficha…">
        <x-slot name="filtros">
            <div class="w-40"><x-admin.fecha name="desde" label="Desde" :value="$filtros['desde']" /></div>
            <div class="w-40"><x-admin.fecha name="hasta" label="Hasta" :value="$filtros['hasta']" /></div>
            <div class="w-40"><x-admin.select name="estado" label="Estado" :options="App\Models\Cobro::ETIQUETAS" :value="$filtros['estado']" nullable /></div>
        </x-slot>

        @include('admin.cobros._tabla')
    </x-admin.listado>
</x-admin.page>
