@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Estado']" :paginator="$tiposRedSocial">
    @forelse ($tiposRedSocial as $tipoRedSocial)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $tipoRedSocial->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$tipoRedSocial->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.tipos-red-social.edit', $tipoRedSocial)"
                :desactivar="$tipoRedSocial->estaActivo() ? Permisos::url('admin.tipos-red-social.desactivar', $tipoRedSocial) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="3" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
