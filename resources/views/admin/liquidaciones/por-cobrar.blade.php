@use('App\Support\Fecha')

{{-- Por cobrar por aseguradora (LiquidacionController::porCobrar): lo aprobado, lo pagado y el saldo. Una cuenta por cobrar aparte de la Caja. --}}
<x-admin.page title="Liquidaciones">
    @include('admin.liquidaciones._pestanas')

    <div class="space-y-6 p-6 text-sm">
        <p class="text-gray-600 dark:text-gray-400">
            Lo que cada aseguradora debe por sus liquidaciones aprobadas. El saldo es el total menos los pagos vigentes; los pagos de la
            aseguradora no pasan por la Caja.
        </p>
        <x-admin.table :headers="['Aseguradora', 'Aprobado', 'Pagado', 'Saldo', 'Pendientes de facturar']">
            @forelse ($porAseguradora as $fila)
                <tr>
                    <td class="px-6 py-4 font-medium">{{ $fila['aseguradora'] }}</td>
                    <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $moneda->formatear($fila['total']) }}</td>
                    <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $moneda->formatear($fila['pagado']) }}</td>
                    <td class="px-6 py-4 whitespace-nowrap font-medium tabular-nums">{{ $moneda->formatear($fila['saldo']) }}</td>
                    <td class="px-6 py-4 tabular-nums">{{ $fila['pendientesDeFacturar'] }}</td>
                    <td class="px-6 py-4 text-right">
                        <a href="{{ route('admin.liquidaciones.index', ['aseguradora' => $fila['responsableId']]) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Sus liquidaciones</a>
                    </td>
                </tr>
                @foreach ($fila['conSaldo'] as $liquidacion)
                    <tr class="text-gray-600 dark:text-gray-400">
                        <td class="py-2 pl-12 pr-6">
                            <a href="{{ route('admin.liquidaciones.show', $liquidacion) }}" class="hover:underline">Liquidación N.º {{ $liquidacion->id }}</a> · corte al {{ Fecha::mostrar($liquidacion->fecha_corte) }} · {{ $liquidacion->etiquetaEstado() }}
                        </td>
                        <td class="px-6 py-2 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($liquidacion->totalEntero()) }}</td>
                        <td class="px-6 py-2 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($liquidacion->pagado()) }}</td>
                        <td class="px-6 py-2 whitespace-nowrap tabular-nums">{{ $liquidacion->formatear($liquidacion->saldo()) }}</td>
                        <td colspan="2"></td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">Todavía no hay liquidaciones aprobadas.</td></tr>
            @endforelse
        </x-admin.table>
    </div>
</x-admin.page>
