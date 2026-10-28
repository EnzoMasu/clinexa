@use('App\Support\Fecha')

<x-admin.table :headers="['N.º', 'Aseguradora', 'Corte', 'Estado', 'Total', 'Saldo', 'Armada por']" :paginator="$liquidaciones">
    @forelse ($liquidaciones as $liquidacion)
        <tr>
            <td class="px-6 py-4 tabular-nums">{{ $liquidacion->id }}</td>
            <td class="px-6 py-4 font-medium">
                {{ $liquidacion->aseguradoraTexto() }}
                @if ($liquidacion->convenio)
                    <div class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $liquidacion->convenio->nombre }}</div>
                @endif
            </td>
            <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($liquidacion->fecha_corte) }}</td>
            <td class="px-6 py-4">
                {{ $liquidacion->etiquetaEstado() }}
                @if ($liquidacion->facturacion === App\Models\Liquidacion::PENDIENTE_DE_FACTURAR)
                    <div class="text-xs text-gray-500 dark:text-gray-400">Pendiente de facturar</div>
                @endif
            </td>
            <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($liquidacion->totalEntero()) }}</td>
            <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $liquidacion->aprobada() ? $liquidacion->formatear($liquidacion->saldo()) : '—' }}</td>
            <td class="px-6 py-4">{{ $liquidacion->armadaPor?->persona?->nombre_completo }}</td>
            <x-admin.actions>
                <a href="{{ route('admin.liquidaciones.show', $liquidacion) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
            </x-admin.actions>
        </tr>
    @empty
        <tr>
            <td colspan="8" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                @if ($busqueda !== '' || array_filter($filtros))
                    No hay liquidaciones con esos filtros.
                @else
                    Todavía no hay liquidaciones.
                @endif
            </td>
        </tr>
    @endforelse
</x-admin.table>
