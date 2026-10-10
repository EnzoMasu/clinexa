{{-- Paciente en una fila de lista: apellido y nombre, edad y ficha (sin contenido clínico). Requiere $paciente. --}}
@php($edad = $paciente->persona->edad())
<span class="min-w-0">
    <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $paciente->persona->nombre_completo }}</span>
    <span class="block text-xs text-gray-500 dark:text-gray-400">
        @if ($edad !== null){{ $edad }} {{ $edad === 1 ? 'año' : 'años' }} · @endif Ficha {{ $paciente->nro_ficha }}
    </span>
</span>
