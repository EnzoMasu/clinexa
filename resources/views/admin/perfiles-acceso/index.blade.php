@use('App\Support\Permisos')

<x-admin.page title="Perfiles de acceso" :create-route="Permisos::url('admin.perfiles-acceso.create')" create-label="Nuevo perfil">
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
            <x-admin.empty-row colspan="5" />
        @endforelse
    </x-admin.table>
</x-admin.page>
