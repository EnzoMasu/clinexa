@use('App\Support\Convenios\EstadoVisacionTurnos')

{{--
    Visación en un turno (docs/convenios-seguros.md): botón "Visación" que abre Cobertura y visaciones con el turno elegido, y, si la
    paciente tiene cobertura vigente, "Seguro: aseguradora" y "Visación cargada" / "Falta visación". Solo con VER sobre VISACIONES; sin
    montos; no frena ninguna acción del turno. Requiere $turno y, opcional, $visacionesTurnos (EstadoVisacionTurnos::para).
--}}
@if (EstadoVisacionTurnos::puedeVer() && ! $turno->tieneEstado(\App\Models\Estado::CANCELADO))
    @php($seguro = ($visacionesTurnos ?? [])[$turno->id] ?? null)
    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1" data-visacion-turno="{{ $turno->id }}">
        <a href="{{ route('admin.coberturas.show', ['paciente' => $turno->paciente_id, 'turno' => $turno->id, 'volver' => request()->getRequestUri()]) }}"
            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-500 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Visación</a>
        @if ($seguro)
            <span class="text-xs text-gray-600 dark:text-gray-400">Seguro: {{ $seguro['seguro'] }}</span>
            <span @class([
                'text-xs font-medium',
                'text-green-700 dark:text-green-400' => $seguro['visada'],
                'text-amber-700 dark:text-amber-300' => ! $seguro['visada'],
            ])>{{ $seguro['visada'] ? 'Visación cargada' : 'Falta visación' }}</span>
        @endif
    </div>
@endif
