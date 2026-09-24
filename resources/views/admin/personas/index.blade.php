<x-admin.page title="Personas" :create-route="route('admin.personas.create')" create-label="Nueva persona">
    <x-admin.search :action="route('admin.personas.index')" :value="$busqueda" placeholder="Buscar por documento, nombre o razón social…" />

    <x-admin.table :headers="['Documento', 'Nombre / Razón social', 'Tipo', 'Contacto', 'Estado']" :paginator="$personas">
        @forelse ($personas as $persona)
            <tr>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $persona->tipoDocumento->codigo }}</span>
                    <span class="font-mono font-medium">{{ $persona->nro_documento }}</span>
                </td>
                <td class="px-6 py-4">
                    <div class="font-medium">{{ $persona->nombre_completo }}</div>
                    @if ($persona->nombre_fantasia)
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $persona->nombre_fantasia }}</div>
                    @endif
                </td>
                <td class="px-6 py-4">{{ $persona->tipo_persona }}</td>
                <td class="px-6 py-4">
                    <div>{{ $persona->email }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $persona->telefono }}</div>
                </td>
                <td class="px-6 py-4"><x-admin.estado-badge :estado="$persona->estado" /></td>
                <x-admin.actions
                    :edit="route('admin.personas.edit', $persona)"
                    :desactivar="$persona->estado === 'ACTIVO' ? route('admin.personas.desactivar', $persona) : null" />
            </tr>
        @empty
            <x-admin.empty-row colspan="6" />
        @endforelse
    </x-admin.table>
</x-admin.page>
