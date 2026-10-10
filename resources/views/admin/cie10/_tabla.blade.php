@use('App\Support\Permisos')

<x-admin.table :headers="['Código', 'Descripción', 'Capítulo', 'Estado']" :paginator="$codigos">
    @forelse ($codigos as $cie10)
        <tr>
            <td class="px-6 py-4 dato font-medium whitespace-nowrap">{{ $cie10->codigo }}</td>
            <td class="px-6 py-4">{{ $cie10->descripcion }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $cie10->capitulo }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$cie10->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.cie10.edit', $cie10)"
                :desactivar="$cie10->estaActivo() ? Permisos::url('admin.cie10.desactivar', $cie10) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="5" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
