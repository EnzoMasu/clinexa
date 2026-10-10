@use('App\Models\Estado')
@use('App\Support\Fecha')

{{--
    Turnos de hoy por preparar: hora, paciente, profesional y estado de la preparación. Nada de contenido
    clínico. "Preparar" crea la consulta EN_PREPARACION (POST); "Continuar" abre su formulario.
--}}
@php
    $boton = 'inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700';
@endphp

<div data-refresco>
    <p class="px-4 pt-3 text-xs text-gray-500 dark:text-gray-400">
        Hoy, {{ Fecha::mostrar(Fecha::hoy()) }} · {{ $turnos->count() }} {{ $turnos->count() === 1 ? 'turno' : 'turnos' }}
    </p>

    @if ($turnos->isEmpty())
        <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
            {{ $busqueda !== '' || $profesional ? 'No hay turnos de hoy con esa búsqueda.' : 'No hay turnos por preparar hoy.' }}
        </p>
    @else
        <ul class="mx-4 my-3 divide-y divide-gray-200 rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
            @foreach ($turnos as $turno)
                <li class="grid gap-x-4 gap-y-2 px-4 py-2.5 sm:grid-cols-[4rem_1fr_14rem_9rem_7rem] sm:items-center">
                    <span class="dato text-xs">{{ substr($turno->hora_inicio, 0, 5) }}</span>
                    @include('admin.atencion._paciente', ['paciente' => $turno->paciente])
                    <span class="truncate text-gray-700 dark:text-gray-300">{{ $turno->profesional->persona->nombre_completo }}</span>
                    <span class="flex flex-wrap gap-1">
                        @include('admin.preparacion._estado', ['consulta' => $turno->consulta])
                        @if ($turno->estado->codigo === Estado::EN_CONSULTA)
                            <x-admin.estado-badge :estado="$turno->estado" />
                        @endif
                    </span>
                    <span>
                        @if ($turno->consulta)
                            @if ($puedeEditar)
                                <a href="{{ route('admin.preparacion.formulario', $turno->consulta) }}" class="{{ $boton }}">Continuar</a>
                            @endif
                        @elseif ($puedeCrear)
                            <form method="POST" action="{{ route('admin.preparacion.preparar', $turno) }}">
                                @csrf
                                <button type="submit" class="{{ $boton }}">Preparar</button>
                            </form>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
