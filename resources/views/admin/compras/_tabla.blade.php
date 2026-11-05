@use('App\Support\Fecha')
@use('App\Support\Permisos')

<x-admin.table :grilla="$grilla" :paginator="$registros">
    @forelse ($registros as $compra)
        <tr>
            <td class="px-6 py-4 tabular-nums">{{ $compra->id }}</td>
            <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($compra->fecha) }}</td>
            <td class="px-6 py-4 font-medium">{{ $compra->proveedor->persona->nombre_completo }}</td>
            <td class="px-6 py-4">{{ $compra->categoriaGasto->nombre }}</td>
            <td class="px-6 py-4">{{ $compra->tipoComprobante->nombre }}{{ $compra->numero_comprobante ? ' '.$compra->numero_comprobante : '' }}</td>
            <td class="px-6 py-4">{{ $compra->condicion->etiqueta() }}</td>
            <td class="px-6 py-4 text-right tabular-nums whitespace-nowrap">{{ $compra->moneda->formatear($compra->total) }}</td>
            <td class="px-6 py-4">
                <span @class([
                    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                    'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300' => $compra->esBorrador(),
                    'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' => $compra->tieneEstado('CONFIRMADO'),
                    'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => $compra->tieneEstado('ANULADO'),
                ])>{{ $compra->etiquetaEstado() }}</span>
            </td>
            <x-admin.actions :edit="$compra->esBorrador() ? Permisos::url('admin.compras.edit', $compra) : null">
                <a href="{{ route('admin.compras.show', $compra) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
            </x-admin.actions>
        </tr>
    @empty
        <x-admin.empty-row colspan="9" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
