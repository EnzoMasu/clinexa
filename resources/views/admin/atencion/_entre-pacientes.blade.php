{{--
    Popup "Entre pacientes" (AtencionController::entrePacientes, por AJAX, SinAlmacenar): los turnos ENTRE_PACIENTES de hoy
    del profesional por atender, por orden de carga (como guía: el profesional elige). Paciente, hora de carga, estado del
    triaje y "Signos alterados"; SIN la observación del triaje. Todo escapado por Blade; se inserta con fragmento-inerte.js.
--}}
@use('App\Models\Estado')
@use('App\Support\Fecha')
@php
    $boton = 'inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700';
    $botonPrincipal = 'inline-flex items-center rounded-md bg-gray-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white';
@endphp
<div data-popup-pendientes="entre-pacientes">
    @if ($turnos->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">No hay pacientes entre pacientes por atender hoy.</p>
    @else
        <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
            @foreach ($turnos as $turno)
                <li data-turno="{{ $turno->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5">
                    <span class="w-16 dato text-xs" title="Hora de carga">{{ substr(Fecha::mostrar($turno->created_at, conHora: true), -5) }}</span>
                    <span class="flex-1">@include('admin.atencion._paciente', ['paciente' => $turno->paciente])</span>
                    <span class="flex items-center gap-2">
                        @if ($turno->estado->codigo === Estado::SALTADO)
                            <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">No se presentó</span>
                        @endif
                        @include('admin.preparacion._estado', ['consulta' => $turno->consulta])
                        @if ($turno->consulta?->con_alertas)
                            <span data-pastilla="signos" class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900 dark:bg-amber-900/50 dark:text-amber-200">Signos alterados</span>
                        @endif
                    </span>
                    <span class="flex flex-wrap items-center gap-2">
                        @if ($puedeAtender)
                            <form method="POST" action="{{ route('admin.atencion.atender', $turno) }}">
                                @csrf
                                <button type="submit" class="{{ $botonPrincipal }}">Atender</button>
                            </form>
                        @endif
                        @if ($puedePasarAusente)
                            @if ($turno->estado->codigo === Estado::SALTADO)
                                <form method="POST" action="{{ route('admin.atencion.pasar-ausente', $turno) }}">
                                    @csrf
                                    <button type="submit" class="{{ $boton }}">Pasar a ausente</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.atencion.no-se-presento', $turno) }}">
                                    @csrf
                                    <button type="submit" class="{{ $boton }}">No se presentó</button>
                                </form>
                            @endif
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
