@use('App\Support\Permisos')

<x-admin.page title="Categorías de gasto" :create-route="Permisos::url('admin.categorias-gasto.create')" create-label="Nueva categoría">
    <x-admin.table :headers="['Nombre']" :paginator="$categoriasGasto">
        @forelse ($categoriasGasto as $categoriaGasto)
            <tr>
                <td class="px-6 py-4 font-medium">{{ $categoriaGasto->nombre }}</td>
                <x-admin.actions :edit="Permisos::url('admin.categorias-gasto.edit', $categoriaGasto)" />
            </tr>
        @empty
            <x-admin.empty-row colspan="2" />
        @endforelse
    </x-admin.table>
</x-admin.page>
