@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Estado']" :paginator="$mediosPago">
    @forelse ($mediosPago as $medioPago)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $medioPago->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$medioPago->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.medios-pago.edit', $medioPago)"
                :desactivar="$medioPago->estaActivo() ? Permisos::url('admin.medios-pago.desactivar', $medioPago) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="3" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
