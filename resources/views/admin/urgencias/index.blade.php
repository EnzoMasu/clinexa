{{--
    Urgencias de hoy, para recepción (UrgenciaController::index): solo los turnos URGENCIA, con paciente, profesional,
    hora de registro y estado. Nada clínico: ni la observación del triaje, ni signos alterados, ni las urgencias que
    marcó el triaje. Con EDITAR sobre TURNOS se cancela una pendiente registrada por error.
--}}
@use('App\Models\Estado')
@use('App\Support\Fecha')
@use('App\Support\Permisos')

<x-admin.page title="Urgencias de hoy" :create-route="Permisos::url('admin.urgencias.create')" create-label="Registrar urgencia">
    <div class="px-6 pt-4 text-sm">
        <a href="{{ route('admin.turnos.index') }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Volver a Turnos</a>
    </div>
    @php($puedeCancelar = Permisos::puedeRuta('admin.urgencias.cancelar'))
    <x-admin.table :headers="['Registro', 'Paciente', 'Profesional', 'Estado']">
        @forelse ($urgencias as $turno)
            <tr data-urgencia="{{ $turno->id }}">
                <td class="px-6 py-4 whitespace-nowrap dato">{{ $turno->llegada_en ? substr(Fecha::mostrar($turno->llegada_en, conHora: true), -5) : '—' }}</td>
                <td class="px-6 py-4">
                    <div class="font-medium">{{ $turno->paciente->persona->nombre_completo }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Ficha {{ $turno->paciente->nro_ficha }}</div>
                </td>
                <td class="px-6 py-4">{{ $turno->profesional->persona->nombre_completo }}</td>
                <td class="px-6 py-4"><x-admin.estado-badge :estado="$turno->estado" /></td>
                <td class="px-6 py-4 text-right whitespace-nowrap">
                    @if ($puedeCancelar && $turno->tieneEstado(Estado::PENDIENTE))
                        <form method="POST" action="{{ route('admin.urgencias.cancelar', $turno) }}"
                            x-data x-on:submit="if (! confirm(@js('¿Cancelar la urgencia de '.$turno->paciente->persona->nombre_completo.'? Use esto solo si se registró por error.'))) $event.preventDefault()">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Cancelar</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">No hay urgencias registradas hoy.</td>
            </tr>
        @endforelse
    </x-admin.table>
</x-admin.page>
