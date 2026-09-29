@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Estado']" :paginator="$categoriasGasto">
    @forelse ($categoriasGasto as $categoriaGasto)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $categoriaGasto->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$categoriaGasto->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.categorias-gasto.edit', $categoriaGasto)"
                :desactivar="$categoriaGasto->estaActivo() ? Permisos::url('admin.categorias-gasto.desactivar', $categoriaGasto) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="3" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
