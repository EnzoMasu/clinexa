@use('App\Support\Permisos')

<x-admin.table :headers="['Departamento', 'País', 'Ciudades', 'Estado']" :paginator="$departamentos">
    @forelse ($departamentos as $departamento)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $departamento->nombre }}</td>
            <td class="px-6 py-4">{{ $departamento->pais->nombre }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $departamento->ciudades_count ?: '—' }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$departamento->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.departamentos.edit', $departamento)"
                :desactivar="$departamento->estaActivo() ? Permisos::url('admin.departamentos.desactivar', $departamento) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="5" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
