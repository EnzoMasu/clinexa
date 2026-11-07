@use('App\Support\Fecha')
@use('App\Support\LiquidacionesBeneficiarios\ArmarLiquidacionBeneficiario')
@use('App\Models\LineaLiquidacionBeneficiario')
@use('App\Support\Repartos\Porcentaje')

{{--
    Las líneas de una liquidación a beneficiario: en la vista previa (arreglos del snapshot) o en el detalle (modelos, con "Quitar", la
    marca de las que dejaron de corresponder y el enlace a la prestación). La paciente, solo en la del profesional. Sin nada clínico.
--}}
@php($enVistaPrevia = $enVistaPrevia ?? false)
<x-admin.table :headers="array_values(array_filter(['Tipo', 'Prestación', 'Fecha', $conPaciente ? 'Paciente' : null, 'Procedimiento', $conPaciente ? null : 'Equipo', 'Base', '%', 'Monto']))">
    @forelse ($filas as $fila)
        @php($l = is_array($fila) ? (object) $fila : $fila)
        @php($modelo = $fila instanceof LineaLiquidacionBeneficiario ? $fila : null)
        @php($marca = $modelo ? ($marcadas[$modelo->id] ?? null) : null)
        <tr @class(['text-gray-400 dark:text-gray-500' => $modelo?->quitada(), 'bg-amber-50 dark:bg-amber-900/20' => $marca])>
            <td class="px-6 py-3">
                {{ LineaLiquidacionBeneficiario::TIPOS[$l->tipo] }}
                @if ($l->tipo === LineaLiquidacionBeneficiario::AJUSTE)
                    <div class="text-xs text-gray-600 dark:text-gray-400">{{ $l->motivo_ajuste }}</div>
                @elseif ($l->tipo === LineaLiquidacionBeneficiario::SALDO_ANTERIOR)
                    <div class="text-xs"><a href="{{ route('admin.liquidaciones-beneficiarios.show', $l->saldo_de_liquidacion_id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">De la liquidación N.º {{ $l->saldo_de_liquidacion_id }}</a></div>
                @endif
                @if ($marca)
                    <div role="status" class="text-xs font-medium text-amber-800 dark:text-amber-200">Dejó de corresponder: {{ $marca }} Quítela antes de enviar.</div>
                @endif
            </td>
            <td class="px-6 py-3 whitespace-nowrap">
                @if ($l->prestacion_id)
                    @if ($vePrestaciones ?? false)
                        <a href="{{ route('admin.prestaciones.show', $l->prestacion_id) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">N.º {{ $l->prestacion_id }}</a>
                    @else
                        N.º {{ $l->prestacion_id }}
                    @endif
                @else
                    —
                @endif
            </td>
            <td class="px-6 py-3 whitespace-nowrap">{{ $l->fecha_servicio ? Fecha::mostrar($l->fecha_servicio) : '—' }}</td>
            @if ($conPaciente)
                <td class="px-6 py-3">{{ $l->paciente ?? '—' }}</td>
            @endif
            <td class="px-6 py-3">{{ $l->procedimiento ?? '—' }}</td>
            @unless ($conPaciente)
                <td class="px-6 py-3">{{ $l->equipo ?? '—' }}</td>
            @endunless
            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $l->monto_base !== null ? $moneda->formatear($l->monto_base) : '—' }}</td>
            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $l->porcentaje !== null ? Porcentaje::texto($l->porcentaje) : '—' }}</td>
            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $moneda->formatear($l->monto) }}</td>
            <td class="px-6 py-3 text-right">
                @if ($modelo)
                    @if ($modelo->quitada())
                        <span class="text-xs text-gray-500 dark:text-gray-400">Quitada el {{ Fecha::mostrar($modelo->liberada_en, conHora: true) }}: {{ $modelo->motivo_liberacion }}</span>
                    @elseif (! $modelo->liberada() && ($puedeQuitar ?? false))
                        <details class="text-left">
                            <summary class="cursor-pointer font-medium text-red-600 dark:text-red-400">Quitar</summary>
                            <form method="POST" action="{{ route('admin.liquidaciones-beneficiarios.lineas.quitar', $modelo) }}" novalidate class="mt-2 w-56 space-y-2">
                                @csrf
                                <x-admin.textarea name="motivo_quitar" label="Motivo" rows="2" />
                                <x-secondary-button type="submit">Quitar (queda para la siguiente)</x-secondary-button>
                            </form>
                        </details>
                    @endif
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="10" class="px-6 py-4 text-gray-500 dark:text-gray-400">{{ $enVistaPrevia ? ArmarLiquidacionBeneficiario::SIN_LINEAS : 'Sin líneas.' }}</td></tr>
    @endforelse
</x-admin.table>
