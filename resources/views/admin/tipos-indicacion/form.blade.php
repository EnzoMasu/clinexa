<x-admin.page :title="$tipoIndicacion->exists ? 'Editar tipo de indicación' : 'Nuevo tipo de indicación'">
    <x-admin.form
        :action="$tipoIndicacion->exists ? route('admin.tipos-indicacion.update', $tipoIndicacion) : route('admin.tipos-indicacion.store')"
        :method="$tipoIndicacion->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.tipos-indicacion.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$tipoIndicacion->nombre" unico="tipo_indicacion.nombre" :unico-ignorar="$tipoIndicacion->id" maxlength="100" required autofocus />

        @if ($tipoIndicacion->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$tipoIndicacion::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$tipoIndicacion->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
