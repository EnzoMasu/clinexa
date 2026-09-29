<x-admin.page :title="$medioPago->exists ? 'Editar medio de pago' : 'Nuevo medio de pago'">
    <x-admin.form
        :action="$medioPago->exists ? route('admin.medios-pago.update', $medioPago) : route('admin.medios-pago.store')"
        :method="$medioPago->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.medios-pago.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$medioPago->nombre" maxlength="50" required autofocus />

        @if ($medioPago->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$medioPago::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$medioPago->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
