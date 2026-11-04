@props(['colspan', 'busqueda' => ''])

<tr>
    <td colspan="{{ $colspan }}" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
        @if ($busqueda !== '')
            No hay resultados para «{{ $busqueda }}».
        @elseif (is_array(request()->query('f')) && implode('', array_map(fn ($v) => is_array($v) ? implode('', $v) : (string) $v, request()->query('f'))) !== '')
            No hay resultados con esos filtros.
        @else
            No hay registros cargados.
        @endif
    </td>
</tr>
