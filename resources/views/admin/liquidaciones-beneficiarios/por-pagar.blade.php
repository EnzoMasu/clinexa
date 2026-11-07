@use('App\Support\Fecha')

{{-- Por pagar por beneficiario (LiquidacionBeneficiarioController::porPagar): lo aprobado, lo pagado, el saldo y el saldo en contra. --}}
<x-admin.page title="Liquidaciones a beneficiarios">
    @include('admin.liquidaciones-beneficiarios._pestanas')

    <div class="space-y-6 p-6 text-sm">
        <p class="text-gray-600 dark:text-gray-400">
            Por beneficiario, lo aprobado en sus liquidaciones, lo pagado y el saldo a pagar (se calcula: no se guarda). El saldo en contra
            (liquidaciones con total negativo por los ajustes) no se paga: pasa a la siguiente.
        </p>
        <x-admin.table :headers="['Beneficiario', 'Tipo', 'Aprobado', 'Pagado', 'Saldo a pagar', 'Saldo en contra', 'Liquidaciones con saldo']">
            @forelse ($porBeneficiario as $fila)
                <tr>
                    <td class="px-6 py-3 font-medium">{{ $fila['beneficiario'] }}</td>
                    <td class="px-6 py-3">{{ $fila['tipo'] }}</td>
                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $moneda->formatear($fila['aprobado']) }}</td>
                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $moneda->formatear($fila['pagado']) }}</td>
                    <td class="px-6 py-3 whitespace-nowrap tabular-nums font-medium">{{ $moneda->formatear($fila['saldo']) }}</td>
                    <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $fila['enContra'] > 0 ? $moneda->formatear($fila['enContra']) : '—' }}</td>
                    <td class="px-6 py-3">
                        @forelse ($fila['conSaldo'] as $liquidacion)
                            <a href="{{ route('admin.liquidaciones-beneficiarios.show', $liquidacion) }}" class="block text-indigo-600 hover:underline dark:text-indigo-400">
                                N.º {{ $liquidacion->id }} · corte {{ Fecha::mostrar($liquidacion->fecha_corte) }} · {{ $liquidacion->formatear($liquidacion->saldo()) }}
                            </a>
                        @empty
                            —
                        @endforelse
                    </td>
                    <td></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-6 py-4 text-gray-500 dark:text-gray-400">Todavía no hay liquidaciones aprobadas.</td></tr>
            @endforelse
        </x-admin.table>
    </div>
</x-admin.page>
