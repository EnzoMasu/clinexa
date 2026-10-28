@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Pide detalle', 'Estado']" :paginator="$motivos">
    @forelse ($motivos as $motivo)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $motivo->nombre }}</td>
            <td class="px-6 py-4">{{ $motivo->requiere_detalle ? 'Sí' : 'No' }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$motivo->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.facturas.motivos.edit', $motivo)"
                :desactivar="$motivo->estaActivo() ? Permisos::url('admin.facturas.motivos.desactivar', $motivo) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="4" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
