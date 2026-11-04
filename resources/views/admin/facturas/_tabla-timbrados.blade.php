@use('App\Support\Fecha')

{{-- Tabla de timbrados (TimbradoController::index): también la respuesta de la búsqueda en vivo. --}}
<x-admin.table :grilla="$grilla" :paginator="$timbrados">
    @forelse ($timbrados as $timbrado)
        <tr>
            <td class="px-6 py-4 font-medium tabular-nums">{{ $timbrado->numero }}</td>
            <td class="px-6 py-4">{{ $timbrado->sucursal->nombre }}</td>
            <td class="px-6 py-4 tabular-nums">{{ $timbrado->punto() }}</td>
            <td class="px-6 py-4 whitespace-nowrap">{{ $timbrado->vigenciaTexto() }}</td>
            <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $timbrado->numero_inicial }} a {{ $timbrado->numero_final }}</td>
            <td class="px-6 py-4 whitespace-nowrap tabular-nums">
                {{ $timbrado->numero_actual >= $timbrado->numero_inicial ? $timbrado->numero_actual : 'Ninguno' }}
                <span class="block text-xs text-gray-500 dark:text-gray-400">Quedan {{ $timbrado->disponibles() }}</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                {{ $timbrado->etiquetaEstado() }}
                @if ($timbrado->historico())
                    <span class="block text-xs text-gray-500 dark:text-gray-400">desde el {{ Fecha::mostrar($timbrado->historico_en) }}</span>
                @endif
            </td>
            <x-admin.actions>
                @if ($puedeHistorico && ! $timbrado->historico())
                    <form method="POST" action="{{ route('admin.facturas.timbrados.historico', $timbrado) }}" class="inline" onsubmit="return confirm('¿Pasar este timbrado a histórico? Ya no va a emitir facturas.')">
                        @csrf
                        <button type="submit" class="font-medium text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Pasar a histórico</button>
                    </form>
                @endif
            </x-admin.actions>
        </tr>
    @empty
        <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">Todavía no hay timbrados.</td></tr>
    @endforelse
</x-admin.table>
