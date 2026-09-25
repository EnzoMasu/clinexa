@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre']" :paginator="$categoriasGasto">
    @forelse ($categoriasGasto as $categoriaGasto)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $categoriaGasto->nombre }}</td>
            <x-admin.actions :edit="Permisos::url('admin.categorias-gasto.edit', $categoriaGasto)" />
        </tr>
    @empty
        <x-admin.empty-row colspan="2" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
