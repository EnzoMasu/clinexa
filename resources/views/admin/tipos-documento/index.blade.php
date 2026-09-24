<x-admin.page title="Tipos de documento" :create-route="route('admin.tipos-documento.create')" create-label="Nuevo tipo">
    <x-admin.table :headers="['Código', 'Nombre', 'Aplica a', 'Estado']" :paginator="$tiposDocumento">
        @forelse ($tiposDocumento as $tipoDocumento)
            <tr>
                <td class="px-6 py-4 font-mono font-medium">{{ $tipoDocumento->codigo }}</td>
                <td class="px-6 py-4">{{ $tipoDocumento->nombre }}</td>
                <td class="px-6 py-4">{{ $tipoDocumento->aplica_a }}</td>
                <td class="px-6 py-4"><x-admin.estado-badge :estado="$tipoDocumento->estado" /></td>
                <x-admin.actions
                    :edit="route('admin.tipos-documento.edit', $tipoDocumento)"
                    :desactivar="$tipoDocumento->estado === 'ACTIVO' ? route('admin.tipos-documento.desactivar', $tipoDocumento) : null" />
            </tr>
        @empty
            <x-admin.empty-row colspan="5" />
        @endforelse
    </x-admin.table>
</x-admin.page>
