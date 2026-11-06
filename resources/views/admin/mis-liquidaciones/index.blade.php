@use('App\Support\Fecha')

{{-- Mis liquidaciones (MisLiquidacionesController::index): las del profesional, solo las aprobadas, de solo lectura. --}}
<x-admin.page title="Mis liquidaciones">
    <div class="space-y-4 p-6 text-sm">
        <p class="text-gray-600 dark:text-gray-400">
            Lo que la clínica le liquidó como profesional, según el reparto de sus prestaciones ya percibidas. Solo se ven las aprobadas; cada
            una con su planilla. Los montos son brutos.
        </p>
        @unless ($esProfesional)
            <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">Su usuario no está vinculado a un profesional: no hay liquidaciones para mostrar.</p>
        @endunless
        <x-admin.table :headers="['N.º', 'Corte', 'Estado', 'Total', 'Pagado', 'Saldo']" :paginator="$liquidaciones">
            @forelse ($liquidaciones as $liquidacion)
                <tr>
                    <td class="px-6 py-3 tabular-nums">{{ $liquidacion->id }}</td>
                    <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($liquidacion->fecha_corte) }}</td>
                    <td class="px-6 py-3">{{ $liquidacion->etiquetaEstado() }}</td>
                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($liquidacion->totalEntero()) }}</td>
                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($liquidacion->pagado()) }}</td>
                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($liquidacion->saldo()) }}</td>
                    <x-admin.actions>
                        <a href="{{ route('admin.mis-liquidaciones.show', $liquidacion) }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Ver</a>
                    </x-admin.actions>
                </tr>
            @empty
                <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">Todavía no tiene liquidaciones aprobadas.</td></tr>
            @endforelse
        </x-admin.table>
    </div>
</x-admin.page>
