<x-admin.page :title="$sucursal->exists ? 'Editar sucursal' : 'Nueva sucursal'">
    <x-admin.form
        :action="$sucursal->exists ? route('admin.sucursales.update', $sucursal) : route('admin.sucursales.store')"
        :method="$sucursal->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.sucursales.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$sucursal->nombre" unico="sucursal.nombre" :unico-ignorar="$sucursal->id" maxlength="100" required autofocus />
        <x-admin.input name="direccion" label="Dirección" :value="$sucursal->direccion" maxlength="200" required />
        <x-admin.input name="telefono" label="Teléfono" :value="$sucursal->telefono" maxlength="20" required />
        <x-geografia.selector-ciudad :value="$sucursal->ciudad_id" />

        @if ($sucursal->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$sucursal::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$sucursal->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
