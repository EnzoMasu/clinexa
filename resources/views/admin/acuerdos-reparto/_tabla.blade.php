@use('App\Models\AcuerdoReparto')
@use('App\Support\Permisos')

<x-admin.table :headers="['Tipo', 'Beneficiario', 'Alcance', 'Porcentaje', 'Vigencia', 'Estado']" :paginator="$acuerdos">
    @forelse ($acuerdos as $acuerdo)
        <tr>
            <td class="px-6 py-4">{{ AcuerdoReparto::TIPOS[$acuerdo->tipo] }}</td>
            <td class="px-6 py-4 font-medium">{{ $acuerdo->beneficiarioTexto() }}</td>
            <td class="px-6 py-4">{{ $acuerdo->alcanceTexto() }}</td>
            <td class="px-6 py-4 whitespace-nowrap tabular-nums">{{ $acuerdo->porcentajeTexto() }}</td>
            <td class="px-6 py-4">{{ $acuerdo->vigenciaTexto() }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$acuerdo->estado" /></td>
            <x-admin.actions>
                @if ($acuerdo->vigente() && ($cambiar = Permisos::url('admin.acuerdos-reparto.cambiar', $acuerdo)))
                    <a href="{{ $cambiar }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Cambiar porcentaje</a>
                @endif
                <a href="{{ route('admin.acuerdos-reparto.show', $acuerdo) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Historial</a>
            </x-admin.actions>
        </tr>
    @empty
        <tr>
            <td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                @if ($busqueda !== '')
                    No hay resultados para «{{ $busqueda }}».
                @else
                    Todavía no hay acuerdos de reparto cargados.
                @endif
            </td>
        </tr>
    @endforelse
</x-admin.table>
