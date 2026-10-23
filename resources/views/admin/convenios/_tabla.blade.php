<x-admin.table :headers="['Aseguradora', 'Convenio', 'Planes', 'Vigencia', 'Estado']" :paginator="$convenios">
    @forelse ($convenios as $convenio)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $convenio->aseguradoraTexto() }}</td>
            <td class="px-6 py-4">{{ $convenio->nombre }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">
                {{ $convenio->planes->filter(fn ($plan) => $plan->estaActivo())->pluck('nombre')->join(', ') ?: '—' }}
            </td>
            <td class="px-6 py-4">{{ $convenio->vigenciaTexto() }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$convenio->estado" /></td>
            <x-admin.actions>
                <a href="{{ route('admin.convenios.show', $convenio) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
            </x-admin.actions>
        </tr>
    @empty
        <tr>
            <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                @if ($busqueda !== '')
                    No hay resultados para «{{ $busqueda }}».
                @else
                    Todavía no hay convenios cargados.
                @endif
            </td>
        </tr>
    @endforelse
</x-admin.table>
