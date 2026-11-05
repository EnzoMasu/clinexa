<x-admin.page :title="$medioPago->exists ? 'Editar medio de pago' : 'Nuevo medio de pago'">
    <x-admin.form
        :action="$medioPago->exists ? route('admin.medios-pago.update', $medioPago) : route('admin.medios-pago.store')"
        :method="$medioPago->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.medios-pago.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$medioPago->nombre" unico="medio_pago.nombre" :unico-ignorar="$medioPago->id" maxlength="50" required autofocus />

        @unless ($medioPago->exists && $medioPago->esEfectivo())
            <div x-data="{ pide: {{ old('requiere_comprobante', $medioPago->requiere_comprobante) ? 'true' : 'false' }} }" class="space-y-3">
                <label class="flex items-center gap-2">
                    <input type="hidden" name="requiere_comprobante" value="0">
                    <input type="checkbox" name="requiere_comprobante" value="1" x-model="pide" @checked(old('requiere_comprobante', $medioPago->requiere_comprobante))
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900">
                    <span class="text-gray-900 dark:text-gray-100">Exige el número de comprobante al cobrar</span>
                </label>
                <div x-show="pide" style="display: none">
                    <x-admin.input name="nombre_comprobante" label="Cómo se llama el comprobante" :value="$medioPago->nombre_comprobante" maxlength="40" placeholder="Código de la transferencia, voucher, boleta…" />
                </div>
            </div>
        @endunless

        @if ($medioPago->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$medioPago::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$medioPago->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
