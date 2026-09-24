<x-admin.page title="Catálogo CIE-10" :create-route="route('admin.cie10.create')" create-label="Nuevo código">
    <form method="GET" action="{{ route('admin.cie10.index') }}" class="flex gap-3 p-4 border-b border-gray-200 dark:border-gray-700">
        <x-text-input name="q" type="search" :value="$busqueda" placeholder="Buscar por código o descripción…" class="block w-full max-w-md" />
        <x-secondary-button type="submit">Buscar</x-secondary-button>
    </form>

    <x-admin.table :headers="['Código', 'Descripción', 'Capítulo']" :paginator="$codigos">
        @forelse ($codigos as $cie10)
            <tr>
                <td class="px-6 py-4 font-mono font-medium whitespace-nowrap">{{ $cie10->codigo }}</td>
                <td class="px-6 py-4">{{ $cie10->descripcion }}</td>
                <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $cie10->capitulo }}</td>
                <x-admin.actions :edit="route('admin.cie10.edit', $cie10)" />
            </tr>
        @empty
            <x-admin.empty-row colspan="4" />
        @endforelse
    </x-admin.table>
</x-admin.page>
