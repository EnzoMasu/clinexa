@use('App\Support\Permisos')

<x-admin.table :headers="['Código', 'Nombre', 'Estado']" :paginator="$origenesTurno">
    @forelse ($origenesTurno as $origenTurno)
        <tr>
            <td class="px-6 py-4 font-mono font-medium">{{ $origenTurno->codigo }}</td>
            <td class="px-6 py-4">{{ $origenTurno->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$origenTurno->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.origenes-turno.edit', $origenTurno)"
                :desactivar="$origenTurno->estaActivo() ? Permisos::url('admin.origenes-turno.desactivar', $origenTurno) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="4" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
