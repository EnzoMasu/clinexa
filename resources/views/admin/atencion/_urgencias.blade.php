{{--
    Popup "Urgencias" (AtencionController::urgencias, por AJAX, SinAlmacenar): los turnos de hoy del profesional por atender
    que son URGENCIA o que el triaje marcó urgentes, una vez cada uno, la más antigua primero. Paciente, origen
    ("Recepción" o "Triaje"), hora de registro o de marca, estado del triaje, "Signos alterados" y la observación del
    triaje (contenido clínico: el controlador registra el VER de cada consulta mostrada). Todo escapado por Blade.
--}}
@use('App\Support\Atencion\PendientesDelProfesional')
@use('App\Support\Fecha')
@php
    $botonPrincipal = 'inline-flex items-center rounded-md bg-red-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-800';
@endphp
<div data-popup-pendientes="urgencias">
    @if ($turnos->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">No hay urgencias por atender.</p>
    @else
        <ul class="divide-y divide-gray-200 rounded-md border border-red-200 text-sm dark:divide-gray-700 dark:border-red-800">
            @foreach ($turnos as $turno)
                @php($momento = PendientesDelProfesional::momento($turno))
                <li data-turno="{{ $turno->id }}" class="space-y-2 px-4 py-2.5">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <span class="w-16 dato text-xs" title="{{ PendientesDelProfesional::origen($turno) === 'Recepción' ? 'Hora de registro' : 'Hora de la marca del triaje' }}">{{ $momento ? substr(Fecha::mostrar($momento, conHora: true), -5) : '—' }}</span>
                        <span class="flex-1">@include('admin.atencion._paciente', ['paciente' => $turno->paciente])</span>
                        <span class="flex items-center gap-2">
                            <span data-origen class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800 dark:bg-red-900/50 dark:text-red-200">{{ PendientesDelProfesional::origen($turno) }}</span>
                            @include('admin.preparacion._estado', ['consulta' => $turno->consulta])
                            @if ($turno->consulta?->con_alertas)
                                <span data-pastilla="signos" class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900 dark:bg-amber-900/50 dark:text-amber-200">Signos alterados</span>
                            @endif
                        </span>
                        @if ($puedeAtender)
                            <form method="POST" action="{{ route('admin.atencion.atender', $turno) }}">
                                @csrf
                                <button type="submit" class="{{ $botonPrincipal }}">Atender</button>
                            </form>
                        @endif
                    </div>
                    @if (filled($turno->consulta?->triaje_observacion))
                        <p data-observacion class="whitespace-pre-line rounded-md bg-red-50 p-2 text-sm text-red-900 dark:bg-red-900/30 dark:text-red-100">{{ $turno->consulta->triaje_observacion }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
