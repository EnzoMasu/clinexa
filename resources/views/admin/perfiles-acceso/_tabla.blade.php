@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Descripción', 'Permisos', 'Usuarios']" :paginator="$perfiles">
    @forelse ($perfiles as $perfil)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $perfil->nombre }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $perfil->descripcion }}</td>
            <td class="px-6 py-4">{{ $perfil->permisos_count }}</td>
            <td class="px-6 py-4">{{ $perfil->users_count }}</td>
            <x-admin.actions :edit="Permisos::url('admin.perfiles-acceso.edit', $perfil)" />
        </tr>
    @empty
        <x-admin.empty-row colspan="5" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
