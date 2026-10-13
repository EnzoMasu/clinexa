{{--
    La hora de un turno ("08:00", o "08:00 – 08:30" con :fin), o en su lugar la pastilla del tipo para los que no
    tienen horario: "Entre pacientes" (azul) o "Urgencia" (rojo). No es contenido clínico. Requiere :turno.
--}}
@props(['turno', 'fin' => false])
@if ($turno->tieneHorario())
    <span {{ $attributes->merge(['class' => 'dato']) }}>{{ substr($turno->hora_inicio, 0, 5) }}@if ($fin) – {{ substr($turno->hora_fin, 0, 5) }}@endif</span>
@elseif ($turno->tipo === \App\Enums\TipoTurno::URGENCIA)
    <span data-pastilla="tipo-urgencia" class="inline-flex whitespace-nowrap rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800 dark:bg-red-900/50 dark:text-red-200">{{ $turno->tipo->etiqueta() }}</span>
@else
    <span data-pastilla="tipo-entre-pacientes" class="inline-flex whitespace-nowrap rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-900/50 dark:text-sky-200">{{ $turno->tipo->etiqueta() }}</span>
@endif
