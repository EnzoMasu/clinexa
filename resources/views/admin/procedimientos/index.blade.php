@use('App\Support\Permisos')

<x-admin.page title="Procedimientos" :create-route="Permisos::url('admin.procedimientos.create')" create-label="Nuevo procedimiento">
    <x-admin.table :headers="['Código', 'Nombre', 'Tipo', 'Duración', 'Estado']" :paginator="$procedimientos">
        @forelse ($procedimientos as $procedimiento)
            <tr>
                <td class="px-6 py-4 font-mono font-medium">{{ $procedimiento->codigo }}</td>
                <td class="px-6 py-4">{{ $procedimiento->nombre }}</td>
                <td class="px-6 py-4">{{ $procedimiento->tipo }}</td>
                <td class="px-6 py-4 whitespace-nowrap">{{ $procedimiento->duracion_estimada_minutos }} min</td>
                <td class="px-6 py-4"><x-admin.estado-badge :estado="$procedimiento->estado" /></td>
                <x-admin.actions
                    :edit="Permisos::url('admin.procedimientos.edit', $procedimiento)"
                    :desactivar="$procedimiento->estado === 'ACTIVO' ? Permisos::url('admin.procedimientos.desactivar', $procedimiento) : null" />
            </tr>
        @empty
            <x-admin.empty-row colspan="6" />
        @endforelse
    </x-admin.table>
</x-admin.page>
