@use('App\Support\Permisos')

<x-admin.table :headers="['Código', 'Nombre', 'Departamentos', 'Estado']" :paginator="$paises">
    @forelse ($paises as $pais)
        <tr>
            <td class="px-6 py-4 font-mono font-medium">{{ $pais->codigo }}</td>
            <td class="px-6 py-4">{{ $pais->nombre }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $pais->departamentos_count ?: '—' }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$pais->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.paises.edit', $pais)"
                :desactivar="$pais->estaActivo() ? Permisos::url('admin.paises.desactivar', $pais) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="5" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
