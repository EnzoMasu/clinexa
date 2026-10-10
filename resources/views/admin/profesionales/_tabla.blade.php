@use('App\Support\Permisos')

<x-admin.table :headers="['Matrícula', 'Nombre', 'Documento', 'Especialidades', 'Estado']" :paginator="$registros">
    @forelse ($registros as $registro)
        <tr>
            <td class="px-6 py-4 dato font-medium">{{ $registro->matricula }}</td>
            <td class="px-6 py-4 font-medium">{{ $registro->persona->nombre_completo }}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $registro->persona->tipoDocumento->codigo }}</span>
                <span class="dato">{{ $registro->persona->nro_documento }}</span>
            </td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">
                {{ $registro->especialidades->map(fn ($especialidad) => $especialidad->nombre.($especialidad->pivot->activa ? '' : ' (deshabilitada)'))->join(', ') ?: '—' }}
            </td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$registro->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.profesionales.edit', $registro)"
                :desactivar="$registro->estaActivo() ? Permisos::url('admin.profesionales.desactivar', $registro) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="6" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
