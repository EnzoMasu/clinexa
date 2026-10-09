@use('App\Models\Turno')
@use('App\Support\Fecha')
@use('App\Support\Permisos')

@php($puedeCambiarEstado = Permisos::puedeRuta('admin.turnos.estado'))

<x-admin.table :headers="['Fecha', 'Horario', 'Paciente', 'Profesional', 'Consultorio', 'Procedimiento', 'Estado']" :paginator="$turnos">
    @forelse ($turnos as $turno)
        <tr>
            <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($turno->fecha) }}</td>
            <td class="px-6 py-4 whitespace-nowrap font-mono">{{ substr($turno->hora_inicio, 0, 5) }} – {{ substr($turno->hora_fin, 0, 5) }}</td>
            <td class="px-6 py-4">
                <div class="font-medium">{{ $turno->paciente->persona->nombre_completo }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Ficha {{ $turno->paciente->nro_ficha }}</div>
            </td>
            <td class="px-6 py-4">{{ $turno->profesional->persona->nombre_completo }}</td>
            <td class="px-6 py-4">{{ $turno->consultorio->nombre_completo }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">
                {{ $turno->procedimiento?->nombre ?? '—' }}
                @if ($turno->origenTurno)
                    <div class="text-xs">Origen: {{ $turno->origenTurno->nombre }}</div>
                @endif
            </td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$turno->estado" /></td>
            {{--
                Botones manuales (Confirmar, Ausente, Cancelar) según las transiciones válidas del estado actual.
                "Atender" y "No se presentó" están en la pantalla Consulta del profesional.
            --}}
            <td class="px-6 py-4 text-right whitespace-nowrap">
                <div class="flex justify-end gap-3">
                    @if ($puedeCambiarEstado)
                        @foreach ($turno->accionesPosibles() as $accion)
                            <form method="POST" action="{{ route('admin.turnos.estado', $turno) }}"
                                @if ($accion === 'cancelar') x-data x-on:submit="if (! confirm(@js('¿Cancelar el turno del '.Fecha::mostrar($turno->fecha).' a las '.substr($turno->hora_inicio, 0, 5).'? El horario queda libre.'))) $event.preventDefault()" @endif>
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="accion" value="{{ $accion }}">
                                <button type="submit" @class([
                                    'text-sm font-medium',
                                    'text-red-600 hover:text-red-900 dark:text-red-400' => $accion === 'cancelar',
                                    'text-indigo-600 hover:text-indigo-900 dark:text-indigo-400' => $accion !== 'cancelar',
                                ])>{{ Turno::ACCIONES[$accion][1] }}</button>
                            </form>
                        @endforeach
                    @endif
                </div>
            </td>
        </tr>
    @empty
        <x-admin.empty-row colspan="8" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
