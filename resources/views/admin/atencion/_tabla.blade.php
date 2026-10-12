@use('App\Models\Estado')
@use('App\Support\Fecha')

{{--
    Pantalla "Consulta": el fragmento que se actualiza solo cada 30 s (AtencionController::index por AJAX,
    que no registra lecturas). Solo datos de agenda y, de los atendidos, hasta 3 códigos CIE-10: nada de
    anamnesis, examen ni motivo. Todo escapado por Blade.
--}}
@php
    $boton = 'inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700';
    $botonPrincipal = 'inline-flex items-center rounded-md bg-gray-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white';
    $titulo = 'text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $lista = 'divide-y divide-gray-200 rounded-md border border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700';
    $hora = fn ($valor) => $valor ? substr(Fecha::mostrar($valor, conHora: true), -5) : '';
@endphp

<div data-refresco class="space-y-8 p-6" aria-live="polite">
    @if ($anteriores > 0)
        <p role="status" class="rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            Tiene {{ $anteriores }} {{ $anteriores === 1 ? 'turno' : 'turnos' }} de días anteriores sin cerrar.
        </p>
    @endif

    {{-- 1) En consulta: las EN_CURSO de este profesional, de cualquier día. --}}
    <section class="space-y-2" aria-labelledby="titulo-en-consulta">
        <h3 id="titulo-en-consulta" class="{{ $titulo }}">En consulta</h3>
        @if ($enCurso->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No tiene pacientes en consulta.</p>
        @else
            @if ($enCurso->count() > 1)
                <p role="status" class="rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    Tiene {{ $enCurso->count() }} consultas en curso al mismo tiempo. Finalice o deshaga las que no correspondan.
                </p>
            @endif
            <ul @class([$lista, 'border-blue-300 dark:border-blue-700' => $enCurso->count() > 1])>
                @foreach ($enCurso as $consulta)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5">
                        <span class="w-24 dato text-xs">
                            @if ($consulta->turno)
                                Turno {{ substr($consulta->turno->hora_inicio, 0, 5) }}
                            @else
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 font-sans font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Urgencia</span>
                            @endif
                        </span>
                        <span class="flex-1">@include('admin.atencion._paciente', ['paciente' => $consulta->historiaClinica->paciente])</span>
                        <span class="text-xs text-gray-600 dark:text-gray-400">
                            {{-- De otro día (en hora de Paraguay, no en UTC): con la fecha. --}}
                            @php($desde = Fecha::mostrar($consulta->iniciada_en, conHora: true))
                            En consulta desde {{ str_starts_with($desde, Fecha::hoy()->format(Fecha::FORMATO)) ? substr($desde, -5) : $desde }}
                        </span>
                        <a href="{{ route('admin.consultas.atencion', $consulta) }}" class="{{ $botonPrincipal }}">Continuar</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- 2) Agenda de hoy: PENDIENTE y CONFIRMADO, por hora. --}}
    <section class="space-y-2" aria-labelledby="titulo-agenda">
        <h3 id="titulo-agenda" class="{{ $titulo }}">Agenda de hoy</h3>
        @if ($agenda->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No quedan turnos por atender hoy.</p>
        @else
            <ul class="{{ $lista }}">
                @foreach ($agenda as $turno)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5">
                        <span class="w-24 dato text-xs">{{ substr($turno->hora_inicio, 0, 5) }}</span>
                        <span class="flex-1">@include('admin.atencion._paciente', ['paciente' => $turno->paciente])</span>
                        <span class="flex items-center gap-2">
                            @if ($turno->estado->codigo === Estado::CONFIRMADO)
                                <span class="inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">Confirmó</span>
                            @endif
                            @include('admin.preparacion._estado', ['consulta' => $turno->consulta])
                        </span>
                        <span class="flex flex-wrap items-center gap-2">
                            @if ($turno->consulta)
                                <a href="{{ route('admin.preparacion.formulario', $turno->consulta) }}" class="{{ $boton }}">Anamnesis</a>
                            @elseif ($puedeAtender)
                                <form method="POST" action="{{ route('admin.preparacion.preparar', $turno) }}">
                                    @csrf
                                    <button type="submit" class="{{ $boton }}">Anamnesis</button>
                                </form>
                            @endif
                            @if ($puedeAtender)
                                <form method="POST" action="{{ route('admin.atencion.atender', $turno) }}">
                                    @csrf
                                    <button type="submit" class="{{ $botonPrincipal }}">Atender</button>
                                </form>
                            @endif
                            @if ($puedePasarAusente)
                                @if ($turno->llegoSuHora())
                                    <form method="POST" action="{{ route('admin.atencion.no-se-presento', $turno) }}">
                                        @csrf
                                        <button type="submit" class="{{ $boton }}">No se presentó</button>
                                    </form>
                                @else
                                    {{-- Antes de la hora del turno: deshabilitado; la actualización automática lo habilita. --}}
                                    <span class="flex items-center gap-1.5">
                                        <button type="button" disabled class="{{ $boton }} cursor-not-allowed opacity-50">No se presentó</button>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Disponible desde {{ substr($turno->hora_inicio, 0, 5) }}</span>
                                    </span>
                                @endif
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- 3) Por llamar de nuevo: SALTADO (se los llamó y no estaban). --}}
    <section class="space-y-2" aria-labelledby="titulo-por-llamar">
        <h3 id="titulo-por-llamar" class="{{ $titulo }}">Por llamar de nuevo</h3>
        @if ($porLlamar->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No hay pacientes por llamar de nuevo.</p>
        @else
            <ul class="{{ $lista }}">
                @foreach ($porLlamar as $turno)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5">
                        <span class="w-24 dato text-xs">{{ substr($turno->hora_inicio, 0, 5) }}</span>
                        <span class="flex-1">@include('admin.atencion._paciente', ['paciente' => $turno->paciente])</span>
                        @include('admin.preparacion._estado', ['consulta' => $turno->consulta])
                        <span class="flex flex-wrap items-center gap-2">
                            @if ($puedeAtender)
                                <form method="POST" action="{{ route('admin.atencion.atender', $turno) }}">
                                    @csrf
                                    <button type="submit" class="{{ $botonPrincipal }}">Atender</button>
                                </form>
                            @endif
                            @if ($puedePasarAusente)
                                <form method="POST" action="{{ route('admin.atencion.pasar-ausente', $turno) }}">
                                    @csrf
                                    <button type="submit" class="{{ $boton }}">Pasar a ausente</button>
                                </form>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- 4) Atendidos y ausentes de hoy: plegables (el estado abierto/cerrado sobrevive a la actualización). --}}
    <details class="group space-y-2" x-bind:open="abiertos.atendidos" x-on:toggle="abiertos.atendidos = $el.open">
        <summary class="{{ $titulo }} cursor-pointer">Atendidos hoy ({{ $atendidos->count() }})</summary>
        @if ($atendidos->isEmpty())
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Todavía no atendió pacientes hoy.</p>
        @else
            <ul class="{{ $lista }} mt-2">
                @foreach ($atendidos as $consulta)
                    @php($codigos = $consulta->diagnosticos->pluck('codigo_cie10'))
                    <li>
                        <a href="{{ route('admin.consultas.show', $consulta) }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <span class="w-24 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $consulta->turno ? substr($consulta->turno->hora_inicio, 0, 5) : 'Urgencia' }}</span>
                            <span class="flex-1">@include('admin.atencion._paciente', ['paciente' => $consulta->historiaClinica->paciente])</span>
                            <span class="font-mono text-xs text-gray-700 dark:text-gray-300">
                                {{ $codigos->take(3)->implode(', ') ?: '—' }}@if ($codigos->count() > 3) <span class="text-gray-500 dark:text-gray-400">+{{ $codigos->count() - 3 }}</span>@endif
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Finalizada {{ $hora($consulta->finalizada_en) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </details>

    <details class="space-y-2" x-bind:open="abiertos.ausentes" x-on:toggle="abiertos.ausentes = $el.open">
        <summary class="{{ $titulo }} cursor-pointer">Ausentes hoy ({{ $ausentes->count() }})</summary>
        @if ($ausentes->isEmpty())
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No hay ausentes hoy.</p>
        @else
            <ul class="{{ $lista }} mt-2">
                @foreach ($ausentes as $turno)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5">
                        <span class="w-24 dato text-xs">{{ substr($turno->hora_inicio, 0, 5) }}</span>
                        <span class="flex-1">@include('admin.atencion._paciente', ['paciente' => $turno->paciente])</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </details>
</div>
