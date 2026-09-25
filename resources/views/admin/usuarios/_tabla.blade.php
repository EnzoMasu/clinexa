@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Email', 'Perfil de acceso', 'Estado', 'Último acceso']" :paginator="$usuarios">
    @forelse ($usuarios as $usuario)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $usuario->name }}</td>
            <td class="px-6 py-4">{{ $usuario->email }}</td>
            <td class="px-6 py-4">{{ $usuario->perfilAcceso?->nombre ?? '—' }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$usuario->estado" /></td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">
                {{ $usuario->ultimo_acceso?->format('d/m/Y H:i') ?? 'Nunca' }}
            </td>
            <x-admin.actions
                :edit="Permisos::url('admin.usuarios.edit', $usuario)"
                :desactivar="$usuario->estado !== 'INACTIVO' && ! $usuario->is(auth()->user()) ? Permisos::url('admin.usuarios.desactivar', $usuario) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="6" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
