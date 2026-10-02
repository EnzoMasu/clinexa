@use('App\Support\Permisos')

<x-admin.table :headers="['Consultorio', 'Sucursal', 'Estado']" :paginator="$consultorios">
    @forelse ($consultorios as $consultorio)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $consultorio->nombre }}</td>
            <td class="px-6 py-4">{{ $consultorio->sucursal->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$consultorio->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.consultorios.edit', $consultorio)"
                :desactivar="$consultorio->estaActivo() ? Permisos::url('admin.consultorios.desactivar', $consultorio) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="4" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
