<x-admin.page :title="$tipo->exists ? 'Editar tipo de comprobante' : 'Nuevo tipo de comprobante'">
    <x-admin.form
        :action="$tipo->exists ? route('admin.tipos-comprobante-compra.update', $tipo) : route('admin.tipos-comprobante-compra.store')"
        :method="$tipo->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.tipos-comprobante-compra.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$tipo->nombre" unico="tipo_comprobante_compra.nombre" :unico-ignorar="$tipo->id" maxlength="100" required autofocus />

        <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">Qué exige a la compra</legend>
            @foreach ($tipo::CASILLAS as $casilla => $etiqueta)
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="{{ $casilla }}" value="0">
                    <input type="checkbox" name="{{ $casilla }}" value="1" @checked(old($casilla, $tipo->{$casilla})) class="rounded border-gray-300 dark:border-gray-700">
                    {{ $etiqueta }}
                </label>
                <x-input-error :messages="$errors->get($casilla)" />
            @endforeach
        </fieldset>

        @if ($tipo->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$tipo::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$tipo->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
