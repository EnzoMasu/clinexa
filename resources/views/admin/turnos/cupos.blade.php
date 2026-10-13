{{--
    Cupo entre pacientes (CupoEntrePacientesController): para una fecha, los profesionales que atienden ese día, con
    su cupo, los ya agendados (entre pacientes no cancelados) y, con CREAR o EDITAR sobre DISPONIBILIDAD, un campo
    para fijarlo (0 a 30). Sin datos clínicos.
--}}
@use('App\Models\CupoEntrePacientes')
@use('App\Support\Fecha')
@use('App\Support\Permisos')

<x-admin.page title="Cupo entre pacientes">
    <div class="space-y-4 p-6">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Cuántos pacientes "entre pacientes" (sin horario) atiende cada profesional en el día. Recepción los agenda desde
            <a href="{{ Permisos::url('admin.turnos.create') ?? route('admin.turnos.index') }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Nuevo turno</a>
            hasta completar el cupo. Sin cupo fijado, es 0.
        </p>

        <form method="GET" action="{{ route('admin.turnos.cupos') }}" class="flex flex-wrap items-end gap-3">
            <div class="w-48">
                <x-admin.fecha name="fecha" label="Fecha" :value="$fecha" />
            </div>
            <x-secondary-button type="submit">Ver</x-secondary-button>
        </form>
        @if ($fechaInvalida)
            <p role="alert" class="text-sm text-red-700 dark:text-red-300">La fecha debe tener el formato dd/mm/aaaa. Se muestra la de hoy.</p>
        @endif
        <x-input-error :messages="$errors->get('fecha')" />
        @if ($puedeFijar && ! $fechaFijable)
            <p role="status" class="text-sm text-amber-800 dark:text-amber-200">El cupo se fija solo de hoy en adelante y hasta {{ CupoEntrePacientes::DIAS_ADELANTE }} días.</p>
        @endif
    </div>

    <x-admin.table :headers="['Profesional', 'Cupo', 'Agendados']">
        @forelse ($filas as $fila)
            @php($profesional = $fila['profesional'])
            <tr data-profesional="{{ $profesional->id }}">
                <td class="px-6 py-4">{{ $profesional->persona->nombre_completo }}</td>
                <td class="px-6 py-4 dato">{{ $fila['cupo'] }}</td>
                <td class="px-6 py-4 dato">{{ $fila['usados'] }} de {{ $fila['cupo'] }}</td>
                <td class="px-6 py-4 text-right">
                    @if ($puedeFijar && $fechaFijable)
                        <form method="POST" action="{{ route('admin.turnos.cupos.fijar', $profesional) }}" class="flex items-start justify-end gap-2">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="fecha" value="{{ Fecha::mostrar($fecha) }}">
                            <input type="hidden" name="fila" value="{{ $profesional->id }}">
                            <div>
                                <label for="cantidad-{{ $profesional->id }}" class="sr-only">Cupo de {{ $profesional->persona->nombre_completo }}</label>
                                <input id="cantidad-{{ $profesional->id }}" type="number" name="cantidad" min="0" max="{{ CupoEntrePacientes::MAXIMO }}" required
                                    value="{{ (int) old('fila') === $profesional->id ? old('cantidad') : $fila['cupo'] }}"
                                    class="w-20 rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <x-input-error class="mt-1 text-left" :messages="[...$errors->get('cantidad.'.$profesional->id), ...((int) old('fila') === $profesional->id ? $errors->get('cantidad') : [])]" />
                            </div>
                            <x-secondary-button type="submit">Fijar</x-secondary-button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">Ningún profesional atiende el {{ Fecha::mostrar($fecha) }}.</td>
            </tr>
        @endforelse
    </x-admin.table>
</x-admin.page>
