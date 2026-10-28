@use('App\Support\Fecha')

<x-admin.table :headers="['Número', 'Fecha', 'Cliente', 'Total', 'Estado', 'Emitida por']" :paginator="$facturas">
    @forelse ($facturas as $factura)
        <tr>
            <td class="px-6 py-4 whitespace-nowrap font-medium tabular-nums">{{ $factura->numero_completo }}</td>
            <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($factura->emitida_en, conHora: true) }}</td>
            <td class="px-6 py-4">
                <span class="font-medium">{{ $factura->cliente_nombre }}</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $factura->cliente_documento }}</span>
            </td>
            <td @class(['px-6 py-4 whitespace-nowrap tabular-nums', 'line-through text-gray-500 dark:text-gray-400' => $factura->anulada()])>{{ $factura->totalTexto() }}</td>
            <td @class(['px-6 py-4 whitespace-nowrap', 'font-semibold text-red-700 dark:text-red-300' => $factura->anulada()])>{{ $factura->etiquetaEstado() }}</td>
            <td class="px-6 py-4">{{ $factura->emisor->persona?->nombre_completo ?? $factura->emisor->email }}</td>
            <x-admin.actions>
                <a href="{{ route('admin.facturas.show', $factura) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
            </x-admin.actions>
        </tr>
    @empty
        <tr>
            <td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                @if ($busqueda !== '')
                    No hay resultados para «{{ $busqueda }}».
                @else
                    No hay facturas con estos filtros.
                @endif
            </td>
        </tr>
    @endforelse
</x-admin.table>
