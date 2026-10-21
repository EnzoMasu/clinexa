@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Estado']" :paginator="$tiposEquipo">
    @forelse ($tiposEquipo as $tipoEquipo)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $tipoEquipo->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$tipoEquipo->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.tipos-equipo.edit', $tipoEquipo)"
                :desactivar="$tipoEquipo->estaActivo() ? Permisos::url('admin.tipos-equipo.desactivar', $tipoEquipo) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="3" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
