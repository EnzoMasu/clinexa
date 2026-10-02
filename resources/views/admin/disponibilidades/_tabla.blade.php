@use('App\Models\Disponibilidad')
@use('App\Support\Fecha')
@use('App\Support\Permisos')

<x-admin.table :headers="['Profesional', 'Consultorio', 'Día', 'Horario', 'Turnos de', 'Vigencia', 'Estado']" :paginator="$disponibilidades">
    @forelse ($disponibilidades as $disponibilidad)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $disponibilidad->profesional->persona->nombre_completo }}</td>
            <td class="px-6 py-4">{{ $disponibilidad->consultorio->nombre_completo }}</td>
            <td class="px-6 py-4">{{ Disponibilidad::DIAS[$disponibilidad->dia_semana] }}</td>
            <td class="px-6 py-4 whitespace-nowrap font-mono">{{ Disponibilidad::hora($disponibilidad->hora_desde) }} – {{ Disponibilidad::hora($disponibilidad->hora_hasta) }}</td>
            <td class="px-6 py-4 whitespace-nowrap">{{ $disponibilidad->duracion_turno_minutos }} min</td>
            <td class="px-6 py-4 whitespace-nowrap">
                {{ Fecha::mostrar($disponibilidad->vigencia_desde) }} – {{ $disponibilidad->vigencia_hasta ? Fecha::mostrar($disponibilidad->vigencia_hasta) : 'sin fin' }}
            </td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$disponibilidad->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.disponibilidades.edit', $disponibilidad)"
                :desactivar="$disponibilidad->estaActivo() ? Permisos::url('admin.disponibilidades.desactivar', $disponibilidad) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="8" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
