@use('App\Support\Permisos')

<x-admin.page title="Medios de pago" :create-route="Permisos::url('admin.medios-pago.create')" create-label="Nuevo medio de pago">
    <x-admin.table :headers="['Nombre']" :paginator="$mediosPago">
        @forelse ($mediosPago as $medioPago)
            <tr>
                <td class="px-6 py-4 font-medium">{{ $medioPago->nombre }}</td>
                <x-admin.actions :edit="Permisos::url('admin.medios-pago.edit', $medioPago)" />
            </tr>
        @empty
            <x-admin.empty-row colspan="2" />
        @endforelse
    </x-admin.table>
</x-admin.page>
