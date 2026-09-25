@use('App\Support\Permisos')

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
                :edit="Permisos::url('admin.personas.edit', $persona)"
                :desactivar="$persona->estado === 'ACTIVO' ? Permisos::url('admin.personas.desactivar', $persona) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="6" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
