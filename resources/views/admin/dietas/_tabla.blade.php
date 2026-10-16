@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Texto', 'Estado']" :paginator="$dietas">
    @forelse ($dietas as $dieta)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $dieta->nombre }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($dieta->texto, 80) }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$dieta->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.dietas.edit', $dieta)"
                :desactivar="$dieta->estaActivo() ? Permisos::url('admin.dietas.desactivar', $dieta) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="4" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
