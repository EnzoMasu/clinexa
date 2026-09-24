@props(['edit', 'desactivar' => null])

<td class="px-6 py-4 text-right whitespace-nowrap space-x-3">
    <a href="{{ $edit }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Editar</a>

    @if ($desactivar)
        <form method="POST" action="{{ $desactivar }}" class="inline" onsubmit="return confirm('¿Desactivar este registro?')">
            @csrf
            @method('PATCH')
            <button type="submit" class="font-medium text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Desactivar</button>
        </form>
    @endif
</td>
