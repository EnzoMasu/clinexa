@use('App\Support\Permisos')

<x-admin.table :headers="['Código', 'Nombre', 'Habilitado en', 'Estado']" :paginator="$tiposDocumento">
    @forelse ($tiposDocumento as $tipoDocumento)
        <tr>
            <td class="px-6 py-4 font-mono font-medium">{{ $tipoDocumento->codigo }}</td>
            <td class="px-6 py-4">{{ $tipoDocumento->nombre }}</td>
            {{-- Módulos que aceptan el tipo (tipo_documento_modulo); "predeterminado" = el que viene elegido. --}}
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">
                {{ $tipoDocumento->modulos->map(fn ($modulo) => $modulo->nombre.($modulo->pivot->es_predeterminado ? ' (predeterminado)' : ''))->join(', ') ?: '—' }}
            </td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$tipoDocumento->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.tipos-documento.edit', $tipoDocumento)"
                :desactivar="$tipoDocumento->estaActivo() ? Permisos::url('admin.tipos-documento.desactivar', $tipoDocumento) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="5" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
