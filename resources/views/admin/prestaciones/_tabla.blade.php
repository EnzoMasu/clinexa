@use('App\Support\Fecha')

<x-admin.table :headers="['Fecha', 'Paciente', 'Profesional', 'Procedimiento', 'Origen', 'Monto', 'Estado']" :paginator="$prestaciones">
    @forelse ($prestaciones as $prestacion)
        <tr>
            <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($prestacion->fecha_servicio) }}</td>
            <td class="px-6 py-4">
                <span class="font-medium">{{ $prestacion->paciente->persona->nombre_completo }}</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $prestacion->paciente->nro_ficha }}</span>
            </td>
            <td class="px-6 py-4">{{ $prestacion->profesional->persona->nombre_completo }}</td>
            <td class="px-6 py-4">{{ $prestacion->procedimiento?->nombre ?? 'Sin procedimiento' }}</td>
            <td class="px-6 py-4">{{ $prestacion->origenTexto() }}</td>
            <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $prestacion->montoTexto() }}</td>
            <td class="px-6 py-4">
                <span @class([
                    'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                    'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300' => $prestacion->pendiente(),
                    'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' => $prestacion->tieneEstado(\App\Models\Estado::TARIFADO),
                    'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => $prestacion->anulada(),
                ])>{{ $prestacion->etiquetaEstado() }}</span>
            </td>
            <x-admin.actions>
                @if ($puedeTarifar && $prestacion->pendiente())
                    <form method="POST" action="{{ route('admin.prestaciones.tarifar', $prestacion) }}" class="inline">
                        @csrf
                        <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Tarifar</button>
                    </form>
                @endif
                @if (isset($pendientesDeReparto[$prestacion->id]))
                    <form method="POST" action="{{ route('admin.prestaciones.repartir', $prestacion) }}" class="inline">
                        @csrf
                        <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Repartir</button>
                    </form>
                @endif
                <a href="{{ route('admin.prestaciones.show', $prestacion) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver</a>
            </x-admin.actions>
        </tr>
    @empty
        <tr>
            <td colspan="8" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                @if ($busqueda !== '')
                    No hay resultados para «{{ $busqueda }}».
                @else
                    No hay prestaciones con estos filtros.
                @endif
            </td>
        </tr>
    @endforelse
</x-admin.table>
