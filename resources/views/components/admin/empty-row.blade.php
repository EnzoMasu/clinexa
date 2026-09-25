@props(['colspan', 'busqueda' => ''])

<tr>
    <td colspan="{{ $colspan }}" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
        @if ($busqueda !== '')
            No hay resultados para «{{ $busqueda }}».
        @else
            No hay registros cargados.
        @endif
    </td>
</tr>
