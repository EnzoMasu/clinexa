@use('App\Support\Fecha')

{{-- Confirmación final del cierre (CajaController::revisarCierre): todavía no se cerró nada. Cancelar vuelve al detalle de la caja. --}}
<x-admin.page title="Confirmar el cierre de la caja del {{ Fecha::mostrar($caja->fecha) }}">
    <div class="space-y-4 p-6 text-sm">
        <p role="alert" class="max-w-3xl rounded-md bg-amber-50 p-3 font-medium text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
            Al cerrar, la caja deja de recibir cobros y queda pendiente de recepción del gerente. Revise lo declarado: no podrá cambiarlo salvo que Gerencia devuelva el arqueo.
        </p>
        <x-admin.table :headers="['Medio de pago', 'Esperado', 'Declarado', 'Diferencia']">
            @foreach ($filas as $medioId => $fila)
                <tr>
                    <td class="px-6 py-3">{{ $medios[$medioId]->nombre }}</td>
                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear($fila['esperado']) }}</td>
                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear($fila['contado']) }}</td>
                    <td @class(['px-6 py-3 whitespace-nowrap tabular-nums', 'font-semibold text-red-700' => $fila['contado'] !== $fila['esperado']])>{{ $caja->moneda->formatear($fila['contado'] - $fila['esperado']) }}</td>
                    <td></td>
                </tr>
            @endforeach
            <tr class="font-semibold">
                <td class="px-6 py-3">Total</td>
                <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear(collect($filas)->sum('esperado')) }}</td>
                <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear(collect($filas)->sum('contado')) }}</td>
                <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $caja->moneda->formatear(collect($filas)->sum('contado') - collect($filas)->sum('esperado')) }}</td>
                <td></td>
            </tr>
        </x-admin.table>
        @if ($conDiferencia)
            <p class="max-w-3xl text-gray-700 dark:text-gray-300"><span class="font-medium">Observación:</span> {{ $motivo }}</p>
        @endif

        <form method="POST" action="{{ route('admin.cajas.cerrar', $caja) }}" class="flex items-center gap-3">
            @csrf
            @foreach ($contado as $medioId => $valor)
                <input type="hidden" name="contado[{{ $medioId }}]" value="{{ $valor }}">
            @endforeach
            @if ($conDiferencia)
                <input type="hidden" name="motivo" value="{{ $motivo }}">
            @endif
            <input type="hidden" name="confirmar" value="1">
            <a href="{{ route('admin.cajas.show', $caja) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-500 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancelar</a>
            <x-primary-button>Cerrar la caja</x-primary-button>
        </form>
    </div>
</x-admin.page>
