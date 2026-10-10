@use('App\Support\Fecha')

<x-admin.table :headers="['Nro. ficha', 'Paciente', 'Documento', 'Consultas', 'Última consulta', 'Estado del paciente']" :paginator="$historias">
    @forelse ($historias as $historia)
        <tr>
            <td class="px-6 py-4 dato font-medium">{{ $historia->paciente->nro_ficha }}</td>
            <td class="px-6 py-4 font-medium">{{ $historia->paciente->persona->nombre_completo }}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $historia->paciente->persona->tipoDocumento->codigo }}</span>
                <span class="dato">{{ $historia->paciente->persona->nro_documento }}</span>
            </td>
            {{-- Conteo y última fecha precargados (withCount / withMax): sin una consulta por fila. --}}
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $historia->consultas_count ?: '—' }}</td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $historia->consultas_max_iniciada_en ? Fecha::mostrar($historia->consultas_max_iniciada_en, conHora: true) : '—' }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$historia->paciente->estado" /></td>
            <x-admin.actions>
                <a href="{{ route('admin.historias-clinicas.show', $historia) }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Ver historia</a>
                {{-- Si el usuario puede atender (calculado una vez por pedido) y el paciente está activo (ya cargado). --}}
                @if ($puedeAtender && $historia->paciente->estaActivo())
                    {{-- POST: crea la consulta EN_CURSO y abre la pantalla de atención. --}}
                    <form method="POST" action="{{ route('admin.atencion.atender-sin-turno', $historia) }}" class="inline">
                        @csrf
                        <button type="submit" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Atender sin turno</button>
                    </form>
                @endif
            </x-admin.actions>
        </tr>
    @empty
        <x-admin.empty-row colspan="7" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
