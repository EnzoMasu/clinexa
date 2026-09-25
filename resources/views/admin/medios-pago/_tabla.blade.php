@use('App\Support\Permisos')

<x-admin.table :headers="['Nombre']" :paginator="$mediosPago">
    @forelse ($mediosPago as $medioPago)
        <tr>
            <td class="px-6 py-4 font-medium">{{ $medioPago->nombre }}</td>
            <x-admin.actions :edit="Permisos::url('admin.medios-pago.edit', $medioPago)" />
        </tr>
    @empty
        <x-admin.empty-row colspan="2" :busqueda="$busqueda" />
    @endforelse
</x-admin.table>
