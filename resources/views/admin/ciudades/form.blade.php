<x-admin.page :title="$ciudad->exists ? 'Editar ciudad' : 'Nueva ciudad'">
    <x-admin.form
        :action="$ciudad->exists ? route('admin.ciudades.update', $ciudad) : route('admin.ciudades.store')"
        :method="$ciudad->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.ciudades.index')"
        width="max-w-2xl">
        {{-- Mismo selector en cascada que Personas y Sucursales, hasta el departamento. --}}
        <x-geografia.selector-ciudad name="departamento_id" :value="$ciudad->departamento_id" hasta="departamento" />

        <x-admin.input name="nombre" label="Nombre" :value="$ciudad->nombre" maxlength="100" required
            unico="ciudad.nombre" :unico-ignorar="$ciudad->id" :unico-con="['departamento_id']" />

        @if ($ciudad->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$ciudad::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$ciudad->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
