@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Estado']" :paginator="$cargosSalud">
    @forelse ($cargosSalud as $cargoSalud)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $cargoSalud->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$cargoSalud->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.cargos-salud.edit', $cargoSalud)"
                :desactivar="$cargoSalud->estaActivo() ? Permisos::url('admin.cargos-salud.desactivar', $cargoSalud) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="3" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
