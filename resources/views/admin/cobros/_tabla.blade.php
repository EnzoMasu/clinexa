@use('App\Support\Fecha')

<x-admin.table :headers="['Fecha', 'Paciente', 'Prestaciones', 'Medios', 'Total', 'Estado', 'Cajero']" :paginator="$cobros">
    @forelse ($cobros as $cobro)
        <tr>
            <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($cobro->cobrado_en, conHora: true) }}</td>
            <td class="px-6 py-4">
                <span class="font-medium">{{ $cobro->paciente->persona->nombre_completo }}</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $cobro->paciente->nro_ficha }}</span>
            </td>
            <td class="px-6 py-4 tabular-nums">{{ $cobro->prestaciones_count }}</td>
            <td class="px-6 py-4">{{ $cobro->lineas->map(fn ($linea) => $linea->medioPago->nombre)->unique()->join(', ') }}</td>
            <td @class(['px-6 py-4 whitespace-nowrap tabular-nums', 'line-through text-gray-500 dark:text-gray-400' => $cobro->revertido()])>{{ $cobro->totalTexto() }}</td>
            <td @class(['px-6 py-4 whitespace-nowrap', 'font-semibold text-red-700 dark:text-red-300' => $cobro->revertido()])>{{ $cobro->etiquetaEstado() }}</td>
            <td class="px-6 py-4">{{ $cobro->cajero->persona?->nombre_completo ?? $cobro->cajero->email }}</td>
            <x-admin.actions>
                <a href="{{ route('admin.cobros.show', $cobro) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
            </x-admin.actions>
        </tr>
    @empty
        <tr>
            <td colspan="8" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                @if ($busqueda !== '')
                    No hay resultados para «{{ $busqueda }}».
                @else
                    No hay cobros con estos filtros.
                @endif
            </td>
        </tr>
    @endforelse
</x-admin.table>
