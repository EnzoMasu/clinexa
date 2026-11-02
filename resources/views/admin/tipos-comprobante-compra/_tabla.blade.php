@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre', 'Número', 'Timbrado', 'Fecha del comprobante', 'Discrimina IVA', 'Estado']" :paginator="$tipos">
    @forelse ($tipos as $tipo)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $tipo->nombre }}</td>
            <td class="px-6 py-4">{{ $tipo->requiere_numero ? ($tipo->numero_formato_legal ? 'Sí (formato legal)' : 'Sí') : 'No' }}</td>
            <td class="px-6 py-4">{{ $tipo->requiere_timbrado ? 'Sí' : 'No' }}</td>
            <td class="px-6 py-4">{{ $tipo->requiere_fecha_comprobante ? 'Sí' : 'No' }}</td>
            <td class="px-6 py-4">{{ $tipo->discrimina_iva ? 'Sí' : 'No' }}</td>
            <td class="px-6 py-4"><x-admin.estado-badge :estado="$tipo->estado" /></td>
            <x-admin.actions
                :edit="Permisos::url('admin.tipos-comprobante-compra.edit', $tipo)"
                :desactivar="$tipo->estaActivo() ? Permisos::url('admin.tipos-comprobante-compra.desactivar', $tipo) : null" />
        </tr>
    @empty
        <x-admin.empty-row colspan="7" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
