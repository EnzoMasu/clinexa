@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre / Razón social', 'Documento', 'Límite de crédito', 'Estado']" :paginator="$registros">
    @forelse ($registros as $registro)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $registro->persona->nombre_completo }}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $registro->persona->tipoDocumento->codigo }}</span>
                <span class="font-mono">{{ $registro->persona->nro_documento }}</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">{{ $registro->limite_credito !== null ? 'Gs. '.number_format((float) $registro->limite_credito, 0, ',', '.') : 'Sin límite' }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$registro->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.responsables-pago.edit', $registro)"
                :desactivar="$registro->estaActivo() ? Permisos::url('admin.responsables-pago.desactivar', $registro) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="5" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
