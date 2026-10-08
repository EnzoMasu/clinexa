@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre / Razón social', 'Documento', 'Categorías', 'Contactos', 'Condiciones comerciales', 'Estado']" :paginator="$registros">
    @forelse ($registros as $registro)
        <tr>
            <td class="px-6 py-4">
                <div class="font-medium">{{ $registro->persona->nombre_completo }}</div>
                {{-- Sitio web: enlace solo si es http/https; si no, texto. --}}
                @if ($registro->sitio_web)
                    <div class="text-xs"><x-admin.enlace-externo :valor="$registro->sitio_web" /></div>
                @endif
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $registro->persona->tipoDocumento->codigo }}</span>
                <span class="font-mono">{{ $registro->persona->nro_documento }}</span>
            </td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $registro->categorias->sortBy('nombre')->pluck('nombre')->join(', ') ?: '—' }}</td>
            {{-- Contactos activos (precargado con withCount: sin una consulta por fila). --}}
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $registro->contactos_activos_count ?: '—' }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ Str::limit($registro->condiciones_comerciales ?? '', 70) }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$registro->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.proveedores.edit', $registro)"
                :desactivar="$registro->estaActivo() ? Permisos::url('admin.proveedores.desactivar', $registro) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="7" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
