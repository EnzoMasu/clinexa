@use('App\Models\Turno')
@use('App\Support\Fecha')

{{-- Un turno en la grilla: hora o tipo, paciente (apellido y nombre, ficha), estado del turno y del triaje, y sus acciones. Requiere $turno. --}}
<div class="mb-1 rounded-md border border-gray-200 p-2 dark:border-gray-700" data-turno="{{ $turno->id }}">
    <div class="flex flex-wrap items-center gap-1.5">
        <x-admin.turno-hora :turno="$turno" class="text-xs" />
        <x-admin.estado-badge :estado="$turno->estado" />
    </div>
    <div class="mt-1 font-medium">{{ $turno->paciente->persona->nombre_completo }}</div>
    <div class="text-xs text-gray-500 dark:text-gray-400">Ficha {{ $turno->paciente->nro_ficha }}</div>
    <div class="mt-1">@include('admin.preparacion._estado', ['consulta' => $turno->consulta])</div>
    @if ($puedeCambiarEstado)
        <div class="mt-1 flex flex-wrap gap-3">
            @foreach ($turno->accionesPosibles() as $accion)
                @continue($accion === 'ausente' && ! $turno->llegoSuHora())
                <form method="POST" action="{{ route('admin.turnos.estado', $turno) }}"
                    @if ($accion === 'cancelar') x-data x-on:submit="if (! confirm(@js('¿Cancelar '.mb_strtolower($turno->descripcionCorta()).' del '.Fecha::mostrar($turno->fecha).'?'))) $event.preventDefault()" @endif>
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="accion" value="{{ $accion }}">
                    <button type="submit" @class([
                        'text-xs font-medium',
                        'text-red-600 hover:text-red-900 dark:text-red-400' => $accion === 'cancelar',
                        'text-indigo-600 hover:text-indigo-900 dark:text-indigo-400' => $accion !== 'cancelar',
                    ])>{{ Turno::ACCIONES[$accion][1] }}</button>
                </form>
            @endforeach
        </div>
    @endif
</div>
