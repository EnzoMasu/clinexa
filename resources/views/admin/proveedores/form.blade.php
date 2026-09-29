<x-admin.page :title="$registro->exists ? 'Editar proveedor' : 'Nuevo proveedor'">
    <x-admin.form
        :action="$registro->exists ? route('admin.proveedores.update', $registro) : route('admin.proveedores.store')"
        :method="$registro->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.proveedores.index')">
        @if ($registro->exists)
            <x-admin.persona-solo-lectura :persona="$registro->persona" />
        @else
            <x-admin.selector-persona :url="route('admin.proveedores.personas-disponibles')" />
        @endif

        <x-admin.textarea name="condiciones_comerciales" label="Condiciones comerciales (opcional)" :value="$registro->condiciones_comerciales" maxlength="5000" />

        @if ($registro->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$registro::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$registro->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
