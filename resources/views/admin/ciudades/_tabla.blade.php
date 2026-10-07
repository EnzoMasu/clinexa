@use('App\Support\Permisos')

<x-admin.table :headers="['Ciudad', 'Departamento', 'País', 'Estado']" :paginator="$ciudades">
    @forelse ($ciudades as $ciudad)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $ciudad->nombre }}</td>
            <td class="px-6 py-4">{{ $ciudad->departamento->nombre }}</td>
            <td class="px-6 py-4">{{ $ciudad->departamento->pais->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$ciudad->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.ciudades.edit', $ciudad)"
                :desactivar="$ciudad->estaActivo() ? Permisos::url('admin.ciudades.desactivar', $ciudad) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="5" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
