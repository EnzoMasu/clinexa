<x-admin.page :title="$cargoSalud->exists ? 'Editar cargo de salud' : 'Nuevo cargo de salud'">
    <x-admin.form
        :action="$cargoSalud->exists ? route('admin.cargos-salud.update', $cargoSalud) : route('admin.cargos-salud.store')"
        :method="$cargoSalud->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.cargos-salud.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$cargoSalud->nombre" unico="cargo_salud.nombre" :unico-ignorar="$cargoSalud->id" maxlength="100" required autofocus />

        @if ($cargoSalud->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$cargoSalud::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$cargoSalud->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
