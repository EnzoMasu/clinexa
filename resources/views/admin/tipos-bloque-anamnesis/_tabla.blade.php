@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Estado']" :paginator="$tiposBloque">
    @forelse ($tiposBloque as $tipoBloque)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $tipoBloque->nombre }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$tipoBloque->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.tipos-bloque-anamnesis.edit', $tipoBloque)"
                :desactivar="$tipoBloque->estaActivo() ? Permisos::url('admin.tipos-bloque-anamnesis.desactivar', $tipoBloque) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="3" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
