<x-admin.page :title="$registro->exists ? 'Editar propietario de equipo' : 'Nuevo propietario de equipo'">
    <x-admin.form
        :action="$registro->exists ? route('admin.propietarios-equipo.update', $registro) : route('admin.propietarios-equipo.store')"
        :method="$registro->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.propietarios-equipo.index')">
        @if ($registro->exists)
            <x-admin.persona-solo-lectura :persona="$registro->persona" />
        @else
            <x-admin.selector-persona :url="route('admin.propietarios-equipo.personas-disponibles')" />
        @endif

        <x-admin.input name="datos_bancarios" label="Datos bancarios (opcional)" :value="$registro->datos_bancarios" maxlength="255" placeholder="Banco, tipo y número de cuenta" />

        @if ($registro->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$registro::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$registro->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
