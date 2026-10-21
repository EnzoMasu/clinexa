<x-admin.page :title="$tipoEquipo->exists ? 'Editar tipo de equipo' : 'Nuevo tipo de equipo'">
    <x-admin.form
        :action="$tipoEquipo->exists ? route('admin.tipos-equipo.update', $tipoEquipo) : route('admin.tipos-equipo.store')"
        :method="$tipoEquipo->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.tipos-equipo.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$tipoEquipo->nombre" unico="tipo_equipo.nombre" :unico-ignorar="$tipoEquipo->id" maxlength="100" required autofocus />

        @if ($tipoEquipo->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$tipoEquipo::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$tipoEquipo->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
