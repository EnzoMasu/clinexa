<x-admin.page title="Sucursales" :create-route="route('admin.sucursales.create')" create-label="Nueva sucursal">
    <x-admin.table :headers="['Nombre', 'Dirección', 'Teléfono', 'Estado']" :paginator="$sucursales">
        @forelse ($sucursales as $sucursal)
            <tr>
                <td class="px-6 py-4 font-medium">{{ $sucursal->nombre }}</td>
                <td class="px-6 py-4">{{ $sucursal->direccion }}</td>
                <td class="px-6 py-4">{{ $sucursal->telefono }}</td>
                <td class="px-6 py-4"><x-admin.estado-badge :estado="$sucursal->estado" /></td>
                <x-admin.actions
                    :edit="route('admin.sucursales.edit', $sucursal)"
                    :desactivar="$sucursal->estado === 'ACTIVO' ? route('admin.sucursales.desactivar', $sucursal) : null" />
            </tr>
        @empty
            <x-admin.empty-row colspan="5" />
        @endforelse
    </x-admin.table>
</x-admin.page>
