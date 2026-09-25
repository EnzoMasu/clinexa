@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Documento', 'Email', 'Perfil de acceso', 'Estado', 'Último acceso']" :paginator="$usuarios">
    @forelse ($usuarios as $usuario)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $usuario->persona->nombre_completo }}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $usuario->persona->tipoDocumento->codigo }}</span>
                <span class="font-mono">{{ $usuario->persona->nro_documento }}</span>
            </td>
            <td class="px-6 py-4">{{ $usuario->email }}</td>
            <td class="px-6 py-4">{{ $usuario->perfilAcceso?->nombre ?? '—' }}</td>
            <td class="px-6 py-4">
                <x-admin.estado-badge :estado="$usuario->estado" />
                @if ($usuario->persona->estado !== 'ACTIVO')
                    {{-- Con la persona inactiva el usuario no puede entrar aunque su estado sea ACTIVO. --}}
                    <span class="block mt-1 text-xs text-red-600 dark:text-red-400">Persona inactiva: sin acceso</span>
                @endif
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">
                {{ $usuario->ultimo_acceso?->format('d/m/Y H:i') ?? 'Nunca' }}
            </td>
            <x-admin.actions
                :edit="Permisos::url('admin.usuarios.edit', $usuario)"
                :desactivar="$usuario->estado !== 'INACTIVO' && ! $usuario->is(auth()->user()) ? Permisos::url('admin.usuarios.desactivar', $usuario) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="7" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
