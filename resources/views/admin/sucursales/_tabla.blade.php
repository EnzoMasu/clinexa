@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Dirección', 'Teléfono', 'Estado']" :paginator="$sucursales">
    @forelse ($sucursales as $sucursal)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $sucursal->nombre }}</td>
            <td class="px-6 py-4">{{ $sucursal->direccion }}</td>
            <td class="px-6 py-4">{{ $sucursal->telefono }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$sucursal->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.sucursales.edit', $sucursal)"
                :desactivar="$sucursal->estado === 'ACTIVO' ? Permisos::url('admin.sucursales.desactivar', $sucursal) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="5" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
