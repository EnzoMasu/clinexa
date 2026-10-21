@use('App\Support\Fecha')

{{-- Detalle de un cobro (CobroController::show, docs/cobros-caja.md): las prestaciones que cubre y los medios. No se edita. --}}
<x-admin.page title="Cobro N.º {{ $cobro->id }}">
    <div class="space-y-6 p-6 text-sm">
        <dl class="grid max-w-3xl grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Fecha</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ Fecha::mostrar($cobro->cobrado_en, conHora: true) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Paciente</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $cobro->paciente->persona->nombre_completo }} ({{ $cobro->paciente->nro_ficha }})</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Total</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $cobro->totalTexto() }}</dd></div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Cajero</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    {{ $cobro->cajero->persona?->nombre_completo ?? $cobro->cajero->email }}
                    @if ($veCaja)
                        · <a href="{{ route('admin.cajas.show', $cobro->caja) }}" class="font-normal text-indigo-600 hover:underline dark:text-indigo-400">Caja del {{ Fecha::mostrar($cobro->caja->fecha) }}</a>
                    @endif
                </dd>
            </div>
        </dl>

        <section aria-labelledby="prestaciones-cobradas" class="max-w-4xl space-y-3">
            <h3 id="prestaciones-cobradas" class="font-semibold text-gray-900 dark:text-gray-100">Prestaciones</h3>
            <x-admin.table :headers="['Fecha de servicio', 'Procedimiento', 'Profesional', 'Monto']">
                @foreach ($cobro->prestaciones as $cubierta)
                    <tr>
                        <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($cubierta->prestacion->fecha_servicio) }}</td>
                        <td class="px-6 py-3">{{ $cubierta->prestacion->procedimiento?->nombre ?? 'Sin procedimiento' }}</td>
                        <td class="px-6 py-3">{{ $cubierta->prestacion->profesional->persona->nombre_completo }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $cobro->moneda->formatear($cubierta->monto) }}</td>
                        <td class="px-6 py-3 text-right">
                            <a href="{{ route('admin.prestaciones.show', $cubierta->prestacion) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        </section>

        <section aria-labelledby="pagos" class="max-w-3xl space-y-3">
            <h3 id="pagos" class="font-semibold text-gray-900 dark:text-gray-100">Pagos</h3>
            <x-admin.table :headers="['Medio de pago', 'Monto', 'Referencia']">
                @foreach ($cobro->lineas as $linea)
                    <tr>
                        <td class="px-6 py-3">{{ $linea->medioPago->nombre }}</td>
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $cobro->moneda->formatear($linea->monto) }}</td>
                        <td class="px-6 py-3">{{ $linea->referencia ?? '—' }}</td>
                        <td></td>
                    </tr>
                @endforeach
            </x-admin.table>
        </section>

        <a href="{{ route('admin.cobros.index') }}" class="inline-block text-gray-600 hover:underline dark:text-gray-400">Volver a Cobros</a>
    </div>
</x-admin.page>
