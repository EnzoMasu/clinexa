@use('App\Models\Turno')
@use('App\Support\Fecha')
@use('App\Support\Permisos')
@use('Illuminate\Support\Facades\Gate')

@php($puedeCambiarEstado = Permisos::puedeRuta('admin.turnos.estado'))
@php($puedeConstanciaRetiro = Permisos::puedeRuta('admin.constancias-retiro.emitir'))
@php($puedeConstanciaEstado = Permisos::puedeRuta('admin.constancias-estado.emitir'))

<x-admin.table :headers="['Fecha', 'Horario', 'Paciente', 'Profesional', 'Consultorio', 'Procedimiento', 'Estado']" :paginator="$turnos">
    @forelse ($turnos as $turno)
        <tr>
            <td class="px-6 py-4 whitespace-nowrap">{{ Fecha::mostrar($turno->fecha) }}</td>
            <td class="px-6 py-4 whitespace-nowrap"><x-admin.turno-hora :turno="$turno" fin /></td>
            <td class="px-6 py-4">
                <div class="font-medium">{{ $turno->paciente->persona->nombre_completo }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Ficha {{ $turno->paciente->nro_ficha }}</div>
            </td>
            <td class="px-6 py-4">{{ $turno->profesional->persona->nombre_completo }}</td>
            <td class="px-6 py-4">{{ $turno->consultorio?->nombre_completo ?? '—' }}</td>
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
                            @if ($accion === 'ausente' && ! $turno->llegoSuHora())
                                {{-- La ausencia solo desde la hora del turno (Turno::pasarA lo valida igual). --}}
                                <span class="flex items-center gap-1.5">
                                    <button type="button" disabled class="cursor-not-allowed text-sm font-medium text-gray-400 dark:text-gray-500">Ausente</button>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Disponible desde {{ $turno->tieneHorario() ? substr($turno->hora_inicio, 0, 5) : Fecha::mostrar($turno->fecha) }}</span>
                                </span>
                                @continue
                            @endif
                            <form method="POST" action="{{ route('admin.turnos.estado', $turno) }}"
                                @if ($accion === 'cancelar') x-data x-on:submit="if (! confirm(@js($turno->tieneHorario() ? '¿Cancelar el turno del '.Fecha::mostrar($turno->fecha).' a las '.substr($turno->hora_inicio, 0, 5).'? El horario queda libre.' : '¿Cancelar '.mb_strtolower($turno->descripcionCorta()).' del '.Fecha::mostrar($turno->fecha).'?'))) $event.preventDefault()" @endif>
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
                    {{-- Constancias (docs/constancias.md): emite la primera vez y después abre la ya emitida. La policy decide quién y para qué turnos. --}}
                    @foreach ([['retiro', $puedeConstanciaRetiro, \App\Models\ConstanciaRetiro::class, 'Constancia de retiro'], ['estado', $puedeConstanciaEstado, \App\Models\ConstanciaEstado::class, 'Constancia de estado']] as [$tipoConstancia, $permitida, $modeloConstancia, $textoConstancia])
                        @if ($permitida && Gate::allows('emitir', [$modeloConstancia, $turno]))
                            <form method="POST" action="{{ route('admin.constancias-'.$tipoConstancia.'.emitir', $turno) }}">
                                @csrf
                                <button type="submit" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">{{ $textoConstancia }}</button>
                            </form>
                        @endif
                    @endforeach
                </div>
            </td>
        </tr>
    @empty
        <x-admin.empty-row colspan="8" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
