@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Descripción', 'Estado']" :paginator="$especialidades">
    @forelse ($especialidades as $especialidad)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $especialidad->nombre }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ Str::limit($especialidad->descripcion ?? '', 80) }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$especialidad->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.especialidades.edit', $especialidad)"
                :desactivar="$especialidad->estaActivo() ? Permisos::url('admin.especialidades.desactivar', $especialidad) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="4" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
