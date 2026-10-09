@use('App\Models\Persona')

{{--
    Barra del paciente de una consulta (preparación y atención): nombre, edad, sexo, documento, ficha,
    profesional y el turno ("Urgencia" sin turno). Requiere $consulta con historiaClinica.paciente.persona,
    turno y profesional.persona.
--}}
@php
    $persona = $consulta->historiaClinica->paciente->persona;
    $edad = $persona->edad();
@endphp

<div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-sm">
    <span class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $persona->nombre_completo }}</span>
    <span class="text-gray-600 dark:text-gray-400">
        @if ($edad !== null){{ $edad }} {{ $edad === 1 ? 'año' : 'años' }} · @endif
        @if (isset(Persona::SEXOS[$persona->sexo])){{ Persona::SEXOS[$persona->sexo] }} · @endif
        {{ $persona->tipoDocumento->codigo }} {{ $persona->nro_documento }} · Ficha {{ $consulta->historiaClinica->paciente->nro_ficha }}
    </span>
    <span class="text-gray-600 dark:text-gray-400">
        @if ($consulta->turno)
            Turno {{ substr($consulta->turno->hora_inicio, 0, 5) }}
        @else
            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Urgencia</span>
        @endif
        · {{ $consulta->profesional->persona->nombre_completo }}
    </span>
</div>
