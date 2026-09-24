@use('App\Support\Permisos')

<x-admin.page title="Catálogo CIE-10" :create-route="Permisos::url('admin.cie10.create')" create-label="Nuevo código">
    <x-admin.search :action="route('admin.cie10.index')" :value="$busqueda" placeholder="Buscar por código o descripción…" />

    <x-admin.table :headers="['Código', 'Descripción', 'Capítulo']" :paginator="$codigos">
        @forelse ($codigos as $cie10)
            <tr>
                <td class="px-6 py-4 font-mono font-medium whitespace-nowrap">{{ $cie10->codigo }}</td>
                <td class="px-6 py-4">{{ $cie10->descripcion }}</td>
                <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $cie10->capitulo }}</td>
                <x-admin.actions :edit="Permisos::url('admin.cie10.edit', $cie10)" />
            </tr>
        @empty
            <x-admin.empty-row colspan="4" />
        @endforelse
    </x-admin.table>
</x-admin.page>
