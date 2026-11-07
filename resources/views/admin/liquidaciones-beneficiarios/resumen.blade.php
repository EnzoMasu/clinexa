{{--
    Resumen por período (LiquidacionBeneficiarioController::resumen, ResumenBeneficiarios): por beneficiario, también la clínica, lo
    devengado, percibido, liquidable, liquidado, pagado y el saldo. Es el servicio de consulta de los informes del objetivo 14.
--}}
<x-admin.page title="Liquidaciones a beneficiarios">
    @include('admin.liquidaciones-beneficiarios._pestanas')

    <div class="space-y-6 p-6 text-sm">
        <form method="GET" action="{{ route('admin.liquidaciones-beneficiarios.resumen') }}" novalidate class="flex flex-wrap items-end gap-3">
            <div class="w-44"><x-admin.fecha name="desde" label="Desde (fecha de servicio)" :value="$desdeTexto" /></div>
            <div class="w-44"><x-admin.fecha name="hasta" label="Hasta" :value="$hastaTexto" /></div>
            <x-secondary-button type="submit">Ver el resumen</x-secondary-button>
        </form>
        @if ($error)
            <p role="alert" class="rounded-md bg-red-50 p-3 text-red-800 dark:bg-red-900/30 dark:text-red-200">{{ $error }}</p>
        @endif

        <x-admin.table :headers="['Beneficiario', 'Tipo', 'Devengado', 'Percibido', 'Liquidable', 'Liquidado', 'Pagado', 'Saldo a pagar']" :acciones="false">
            @forelse ($filas as $fila)
                <tr @class(['font-medium' => $fila['clave'] === 'CLINICA'])>
                    <td class="px-6 py-3">{{ $fila['beneficiario'] }}</td>
                    <td class="px-6 py-3">{{ $fila['tipo'] }}</td>
                    @foreach (['devengado', 'percibido', 'liquidable', 'liquidado', 'pagado', 'saldo'] as $columna)
                        <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $fila[$columna] === null ? '—' : $moneda->formatear($fila[$columna]) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="8" class="px-6 py-4 text-gray-500 dark:text-gray-400">Sin movimientos en el período.</td></tr>
            @endforelse
        </x-admin.table>

        <div class="space-y-1 text-gray-600 dark:text-gray-400">
            <p><strong>Devengado</strong>: el reparto vigente de las prestaciones no anuladas con fecha de servicio en el período. <strong>Percibido</strong>: de eso, lo cobrado a la paciente y, si hay aseguradora, con su liquidación pagada del todo (sus pagos parciales no se asignan por prestación). <strong>Liquidable</strong>: lo percibido todavía sin liquidar.</p>
            <p><strong>Liquidado</strong>: lo que está en liquidaciones aprobadas (con los ajustes en contra), por la fecha de servicio. <strong>Pagado</strong>: los pagos con fecha en el período. <strong>Saldo a pagar</strong>: lo que falta pagar hoy de sus liquidaciones aprobadas con corte hasta el fin del período.</p>
            <p>La clínica no se liquida. Su parte <strong>no es su ganancia</strong>: para eso faltan los gastos y los sueldos (objetivo 14). La prestación no tiene sucursal propia: el resumen es de toda la clínica.</p>
        </div>
    </div>
</x-admin.page>
