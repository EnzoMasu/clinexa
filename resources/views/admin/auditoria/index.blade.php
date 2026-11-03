<x-admin.page title="Auditoría">
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Registro de solo lectura de los cambios de datos, las lecturas de los módulos sensibles y los eventos de inicio de sesión.
        No se puede modificar ni borrar.
    </p>

    <x-admin.listado :action="route('admin.auditoria.index')" :busqueda="$busqueda" placeholder="Buscar por registro o detalle…">
        {{-- Usuario, acción, módulo y fechas: en la fila de filtros de la grilla (docs/grillas.md). --}}

        @include('admin.auditoria._tabla')
    </x-admin.listado>
</x-admin.page>
