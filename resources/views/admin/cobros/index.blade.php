{{-- Cobros (CobroController::index, docs/cobros-caja.md). Sin contenido clínico. --}}
<x-admin.page title="Cobros" :create-route="$puedeCobrar ? route('admin.cobros.create') : null" create-label="Cobrar">
    @include('admin.cobros._pestanas', ['activa' => 'cobros'])

    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        La paciente paga después de finalizar la consulta, siempre el total de las prestaciones que elige. Un cobro no se edita: si hace
        falta, se revierte completo desde su detalle.
    </p>

    <x-admin.listado :action="route('admin.cobros.index')" :busqueda="$busqueda" placeholder="Buscar por paciente, documento o ficha…">
        {{-- Fechas, estado y demás: en la fila de filtros de la grilla (docs/grillas.md). --}}
        @include('admin.cobros._tabla')
    </x-admin.listado>
</x-admin.page>
