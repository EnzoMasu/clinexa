@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Tipo', 'Dueño', 'Procedimientos que lo usan', 'Estado']" :paginator="$equipos">
    @forelse ($equipos as $equipo)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $equipo->nombre }}</td>
            <td class="px-6 py-4">{{ $equipo->tipo->nombre }}</td>
            <td class="px-6 py-4">{{ $equipo->duenoTexto() }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $equipo->procedimientos->sortBy('nombre')->pluck('nombre')->join(', ') ?: '—' }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$equipo->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.equipos.edit', $equipo)"
                :desactivar="$equipo->estaActivo() ? Permisos::url('admin.equipos.desactivar', $equipo) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="6" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
