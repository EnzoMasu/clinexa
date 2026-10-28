@use('App\Support\Fecha')

{{-- Prestaciones de aseguradora sin la firma recibida (no entran en la liquidación). Con el enlace a la página de la paciente, donde se marca la firma. --}}
<x-admin.table :headers="['Fecha', 'Paciente', 'Procedimiento', 'Aseguradora', 'Visación', 'A cargo de la aseguradora']">
    @forelse ($prestaciones as $prestacion)
        <tr>
            <td class="px-6 py-3 whitespace-nowrap">{{ Fecha::mostrar($prestacion->fecha_servicio) }}</td>
            <td class="px-6 py-3">{{ $prestacion->paciente->persona->nombre_completo }}</td>
            <td class="px-6 py-3">{{ $prestacion->procedimiento?->nombre ?? 'Sin procedimiento' }}</td>
            <td class="px-6 py-3">{{ $prestacion->responsablePago?->persona?->nombre_completo }}</td>
            <td class="px-6 py-3">N.º {{ $prestacion->numero_visacion }}</td>
            <td class="px-6 py-3 whitespace-nowrap tabular-nums">{{ $prestacion->montoAseguradoraTexto() }}</td>
            <td class="px-6 py-3 text-right">
                @if ($veCobertura)
                    <a href="{{ route('admin.coberturas.show', ['paciente' => $prestacion->paciente_id, 'consulta' => $prestacion->consulta_id]) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Marcar la firma</a>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="px-6 py-4 text-gray-500 dark:text-gray-400">No hay prestaciones de aseguradora sin la firma recibida.</td></tr>
    @endforelse
</x-admin.table>
