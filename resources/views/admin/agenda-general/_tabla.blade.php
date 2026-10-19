@use('App\Support\Fecha')

{{--
    Grilla del día: una columna por profesional, los horarios en filas. Libre: lleva al alta de turno con profesional,
    fecha y hora ya cargados. Ocupada: paciente y estado. Entre pacientes y urgencias, en una franja aparte por profesional con su
    cupo. Solo datos de agenda: del triaje, únicamente Sin triaje / En triaje / Con triaje.
    Pendiente (docs/agenda-urgencias.md): "Próximo horario libre" del profesional al filtrar por especialidad.
--}}
@php
    $ayer = Fecha::mostrar($fecha->copy()->subDay());
    $manana = Fecha::mostrar($fecha->copy()->addDay());
    $enlaceDia = fn (string $dia) => route('admin.agenda-general.index', array_filter([...$filtros, 'fecha' => $dia]));
    $boton = 'inline-flex items-center rounded-md border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700';
@endphp

<div data-refresco>
    <div class="flex flex-wrap items-center gap-3 px-4 pt-3 text-sm">
        <a href="{{ $enlaceDia($ayer) }}" class="{{ $boton }}">← Día anterior</a>
        <span class="font-medium dato">{{ Fecha::mostrar($fecha) }}</span>
        <a href="{{ $enlaceDia($manana) }}" class="{{ $boton }}">Día siguiente →</a>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $profesionales->total() }} {{ $profesionales->total() === 1 ? 'profesional' : 'profesionales' }}</span>
    </div>
    @if ($fechaInvalida)
        <p role="alert" class="px-4 pt-2 text-sm text-red-700 dark:text-red-300">La fecha debe tener el formato dd/mm/aaaa. Se muestra la de hoy.</p>
    @endif

    @if ($columnas->isEmpty())
        <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">No hay profesionales ni turnos para esos filtros en esa fecha.</p>
    @else
        <div class="overflow-x-auto px-4 py-3">
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <tr>
                        <th class="w-16 px-2 py-2 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Hora</th>
                        @foreach ($columnas as $columna)
                            @php($profesional = $columna['profesional'])
                            <th scope="col" data-profesional="{{ $profesional->id }}" class="min-w-48 border-s border-gray-200 px-2 py-2 text-left align-top dark:border-gray-700">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $profesional->persona->nombre_completo }}</div>
                                <div class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $profesional->especialidadesActivas->pluck('nombre')->join(', ') ?: 'Sin especialidad' }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="text-gray-900 dark:text-gray-100">
                    {{-- Franja aparte: entre pacientes y urgencias, con el cupo del día. --}}
                    <tr class="bg-gray-50 align-top dark:bg-gray-900/40" data-franja="sin-horario">
                        <th scope="row" class="px-2 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Sin horario</th>
                        @foreach ($columnas as $columna)
                            @php($profesional = $columna['profesional'])
                            <td class="border-s border-gray-200 px-2 py-2 dark:border-gray-700">
                                <div class="mb-1 text-xs font-medium text-gray-600 dark:text-gray-300" data-cupo>Cupo: {{ $columna['usados'] }} de {{ $columna['cupo'] }}</div>
                                @foreach ($columna['turnos']->reject(fn ($turno) => $turno->tieneHorario()) as $turno)
                                    @include('admin.agenda-general._turno', ['turno' => $turno])
                                @endforeach
                                @if ($puedeAgendar && $columna['usados'] < $columna['cupo'])
                                    <a href="{{ route('admin.turnos.create', ['modalidad' => 'entre_pacientes', 'profesional' => $profesional->id, 'fecha' => Fecha::mostrar($fecha)]) }}"
                                        class="text-xs font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Agendar entre pacientes</a>
                                @endif
                            </td>
                        @endforeach
                    </tr>

                    @forelse ($filas as $hora => $celdas)
                        <tr class="border-t border-gray-200 align-top dark:border-gray-700" data-hora="{{ $hora }}">
                            <th scope="row" class="px-2 py-2 text-left dato">{{ $hora }}</th>
                            @foreach ($columnas as $columna)
                                @php($celda = $celdas[$columna['profesional']->id] ?? null)
                                <td class="border-s border-gray-200 px-2 py-2 dark:border-gray-700">
                                    @if ($celda && isset($celda['turnos']))
                                        @foreach ($celda['turnos'] as $turno)
                                            @include('admin.agenda-general._turno', ['turno' => $turno])
                                        @endforeach
                                    @elseif ($celda && isset($celda['continua']))
                                        {{-- Un turno largo que empezó en una fila anterior sigue ocupando esta. --}}
                                        <div data-continua="{{ $celda['continua']->id }}" class="rounded-md border-s-4 border-indigo-300 bg-indigo-50 px-2 py-1 text-xs text-gray-700 dark:border-indigo-700 dark:bg-indigo-900/30 dark:text-gray-300">
                                            Continúa: {{ $celda['continua']->paciente->persona->nombre_completo }}
                                            <span class="dato">(hasta {{ substr($celda['continua']->hora_fin, 0, 5) }})</span>
                                        </div>
                                    @elseif ($celda && isset($celda['libre']))
                                        @if ($puedeAgendar)
                                            <a href="{{ route('admin.turnos.create', ['profesional' => $columna['profesional']->id, 'fecha' => Fecha::mostrar($fecha), 'hora' => $hora]) }}" data-libre
                                                class="block rounded-md border border-dashed border-green-400 px-2 py-1 text-xs font-medium text-green-800 hover:bg-green-50 dark:border-green-700 dark:text-green-300 dark:hover:bg-green-900/30">Libre · dar turno</a>
                                        @else
                                            <span data-libre class="text-xs text-green-800 dark:text-green-300">Libre</span>
                                        @endif
                                    @elseif ($celda)
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Ocupado</span>
                                    @else
                                        <span class="text-xs text-gray-400 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ $columnas->count() + 1 }}" class="px-2 py-4 text-sm text-gray-500 dark:text-gray-400">Ningún profesional tiene horarios ese día.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($profesionales->hasPages())
            <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-700">{{ $profesionales->links() }}</div>
        @endif
    @endif
</div>
