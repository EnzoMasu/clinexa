{{-- "apilado": las acciones una debajo de la otra (la columna fija mide la más larga; ver docs/grillas.md). Para 3 o más acciones, o textos largos. --}}
{{-- edit / desactivar: URL de la acción, o null para no mostrarla (sin permiso o no corresponde). El contenido va antes (otros enlaces). --}}
@props(['edit' => null, 'desactivar' => null, 'apilado' => false])

<td class="px-6 py-4 text-right whitespace-nowrap @unless ($apilado) space-x-3 @endunless">
    @if ($apilado)<div class="acciones-apiladas flex">@endif
    {{ $slot }}

    @if ($edit)
        <a href="{{ $edit }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Editar</a>
    @endif

    @if ($desactivar)
        <form method="POST" action="{{ $desactivar }}" class="inline" onsubmit="return confirm('¿Desactivar este registro?')">
            @csrf
            @method('PATCH')
            <button type="submit" class="font-medium text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Desactivar</button>
        </form>
    @endif
    @if ($apilado)</div>@endif
</td>
