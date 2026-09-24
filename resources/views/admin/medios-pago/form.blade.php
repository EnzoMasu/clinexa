<x-admin.page :title="$medioPago->exists ? 'Editar medio de pago' : 'Nuevo medio de pago'">
    <x-admin.form
        :action="$medioPago->exists ? route('admin.medios-pago.update', $medioPago) : route('admin.medios-pago.store')"
        :method="$medioPago->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.medios-pago.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$medioPago->nombre" maxlength="50" required autofocus />
    </x-admin.form>
</x-admin.page>
