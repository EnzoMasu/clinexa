@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Estado']" :paginator="$categoriasProveedor">
    @forelse ($categoriasProveedor as $categoriaProveedor)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $categoriaProveedor->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$categoriaProveedor->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.categorias-proveedor.edit', $categoriaProveedor)"
                :desactivar="$categoriaProveedor->estaActivo() ? Permisos::url('admin.categorias-proveedor.desactivar', $categoriaProveedor) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="3" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
