@use('App\Support\Permisos')

<x-admin.table :headers="['Código', 'Descripción', 'Capítulo']" :paginator="$codigos">
    @forelse ($codigos as $cie10)
        <tr>
            <td class="px-6 py-4 font-mono font-medium whitespace-nowrap">{{ $cie10->codigo }}</td>
            <td class="px-6 py-4">{{ $cie10->descripcion }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $cie10->capitulo }}</td>
            <x-admin.actions :edit="Permisos::url('admin.cie10.edit', $cie10)" />
        </tr>
    @empty
        <x-admin.empty-row colspan="4" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
