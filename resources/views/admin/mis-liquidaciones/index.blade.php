@use('App\Support\Fecha')

{{-- Mis liquidaciones (MisLiquidacionesController::index): las suyas como profesional y como dueño de equipo, solo las aprobadas, de solo lectura. --}}
<x-admin.page title="Mis liquidaciones">
    <div class="space-y-4 p-6 text-sm">
        <p class="text-gray-600 dark:text-gray-400">
            Lo que la clínica le liquidó como profesional o como dueño de equipo, según el reparto de las prestaciones ya percibidas. Solo se ven las aprobadas; cada
            una con su planilla. Los montos son brutos.
        </p>
        @unless ($esBeneficiario)
            <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">Su usuario no está vinculado a un profesional ni a un dueño de equipo: no hay liquidaciones para mostrar.</p>
        @endunless
        <x-admin.table :headers="['N.º', 'Rol', 'Corte', 'Estado', 'Total', 'Pagado', 'Saldo']" :paginator="$liquidaciones">
            @forelse ($liquidaciones as $liquidacion)
                <tr>
                    <td class="px-6 py-3 tabular-nums">{{ $liquidacion->id }}</td>
                    <td class="px-6 py-3 whitespace-nowrap">{{ $liquidacion->tipoTexto() }}</td>
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
                <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">Todavía no tiene liquidaciones aprobadas.</td></tr>
            @endforelse
        </x-admin.table>
    </div>
</x-admin.page>
