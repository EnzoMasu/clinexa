@use('App\Support\Fecha')

{{-- Listado de cajas (CajaController::index): también la respuesta de la búsqueda en vivo. --}}
<x-admin.table :grilla="$grilla" :paginator="$cajas">
    @forelse ($cajas as $caja)
        <tr>
            <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($caja->fecha) }}</td>
            <td class="px-6 py-3">{{ $caja->cajero->persona?->nombre_completo ?? $caja->cajero->email }}</td>
            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->montoInicialTexto() }}</td>
            <td class="px-6 py-3 tabular-nums">{{ $caja->cobros_count }}</td>
            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear($caja->cobros_sum_total ?? 0) }}</td>
            <td class="px-6 py-3">{{ $caja->etiquetaEstado() }}</td>
            <td class="px-6 py-3 text-right">
                <a href="{{ route('admin.cajas.show', $caja) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">No hay cajas con este filtro.</td></tr>
    @endforelse
</x-admin.table>
