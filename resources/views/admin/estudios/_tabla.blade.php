@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Estado']" :paginator="$estudios">
    @forelse ($estudios as $estudio)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $estudio->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$estudio->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.estudios.edit', $estudio)"
                :desactivar="$estudio->estaActivo() ? Permisos::url('admin.estudios.desactivar', $estudio) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="3" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
