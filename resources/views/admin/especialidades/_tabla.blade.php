@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Descripción']" :paginator="$especialidades">
    @forelse ($especialidades as $especialidad)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $especialidad->nombre }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ Str::limit($especialidad->descripcion ?? '', 80) }}</td>
            <x-admin.actions :edit="Permisos::url('admin.especialidades.edit', $especialidad)" />
        </tr>
    @empty
        <x-admin.empty-row colspan="3" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
