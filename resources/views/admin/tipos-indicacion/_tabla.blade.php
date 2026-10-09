@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Estado']" :paginator="$tiposIndicacion">
    @forelse ($tiposIndicacion as $tipoIndicacion)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $tipoIndicacion->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$tipoIndicacion->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.tipos-indicacion.edit', $tipoIndicacion)"
                :desactivar="$tipoIndicacion->estaActivo() ? Permisos::url('admin.tipos-indicacion.desactivar', $tipoIndicacion) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="3" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
