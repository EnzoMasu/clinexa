<x-admin.page :title="$motivo->exists ? 'Editar motivo de anulación' : 'Nuevo motivo de anulación'">
    <x-admin.form
        :action="$motivo->exists ? route('admin.facturas.motivos.update', $motivo) : route('admin.facturas.motivos.store')"
        :method="$motivo->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.facturas.motivos')">
        <x-admin.input name="nombre" label="Nombre" :value="$motivo->nombre" maxlength="100" required autofocus />
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="hidden" name="requiere_detalle" value="0">
            <input type="checkbox" name="requiere_detalle" value="1" @checked(old('requiere_detalle', $motivo->requiere_detalle)) class="rounded border-gray-300 dark:border-gray-700">
            Pide un detalle obligatorio al anular
        </label>

        @if ($motivo->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$motivo::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$motivo->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
