@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Documento', 'Cargo', 'Registro profesional', 'Estado']" :paginator="$registros">
    @forelse ($registros as $registro)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $registro->persona->nombre_completo }}</td>
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="dato">{{ $registro->persona->documentoPrincipal() }}</span>
            </td>
            <td class="px-6 py-4">{{ $registro->cargoSalud->nombre }}</td>
            <td class="px-6 py-4 dato">{{ $registro->registro_profesional ?? '—' }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$registro->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.personal-salud.edit', $registro)"
                :desactivar="$registro->estaActivo() ? Permisos::url('admin.personal-salud.desactivar', $registro) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="6" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
