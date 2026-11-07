@use('App\Support\Fecha')
@use('App\Support\Permisos')

{{-- Una liquidación propia (MisLiquidacionesController::show): solo lectura, con la planilla. Sin nada clínico. --}}
<x-admin.page :title="'Mi liquidación N.º '.$liquidacion->id">
    <div class="space-y-6 p-6 text-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-3">
                <div><dt class="text-gray-500 dark:text-gray-400">Rol</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $liquidacion->tipoTexto() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Fecha de corte</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($liquidacion->fecha_corte) }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Estado</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $liquidacion->etiquetaEstado() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Total</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $liquidacion->formatear($liquidacion->totalEntero()) }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Pagado / saldo</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $liquidacion->formatear($liquidacion->pagado()) }} / {{ $liquidacion->formatear($liquidacion->saldo()) }}</dd></div>
            </dl>
            <div class="flex flex-col items-end gap-2">
                @if (Permisos::puedeRuta('admin.mis-liquidaciones.planilla'))
                    <a href="{{ route('admin.mis-liquidaciones.planilla', $liquidacion) }}" target="_blank" rel="noopener" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">Planilla (PDF)</a>
                @endif
                <a href="{{ route('admin.mis-liquidaciones.index') }}" class="text-gray-600 hover:underline dark:text-gray-400">Volver a Mis liquidaciones</a>
            </div>
        </div>
        @if ($liquidacion->saldoEnContra() > 0)
            <p role="status" class="rounded-md bg-amber-50 p-3 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">Saldo en contra: {{ $liquidacion->formatear($liquidacion->saldoEnContra()) }}. Se descuenta en su liquidación siguiente.</p>
        @endif

        @include('admin.liquidaciones-beneficiarios._lineas', ['filas' => $lineas, 'conPaciente' => $liquidacion->deProfesional(), 'moneda' => $liquidacion->moneda, 'marcadas' => collect(), 'puedeQuitar' => false, 'vePrestaciones' => false])

        <section aria-labelledby="pagos" class="space-y-3">
            <h3 id="pagos" class="font-semibold text-gray-900 dark:text-gray-100">Pagos</h3>
            <x-admin.table :headers="['Fecha', 'Medio', 'Comprobante', 'Monto']">
                @forelse ($liquidacion->pagosVigentes->sortBy('id') as $pago)
                    <tr>
                        <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($pago->fecha) }}</td>
                        <td class="px-6 py-3">{{ $pago->medioPago->nombre }}</td>
                        <td class="px-6 py-3">{{ $pago->referencia ?? '—' }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($pago->montoEntero()) }}</td>
                        <td></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-4 text-gray-500 dark:text-gray-400">Todavía no hay pagos.</td></tr>
                @endforelse
            </x-admin.table>
        </section>
    </div>
</x-admin.page>
