<x-admin.page :title="$tipoBloque->exists ? 'Editar tipo de bloque de anamnesis' : 'Nuevo tipo de bloque de anamnesis'">
    <x-admin.form
        :action="$tipoBloque->exists ? route('admin.tipos-bloque-anamnesis.update', $tipoBloque) : route('admin.tipos-bloque-anamnesis.store')"
        :method="$tipoBloque->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.tipos-bloque-anamnesis.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$tipoBloque->nombre" unico="tipo_bloque_anamnesis.nombre" :unico-ignorar="$tipoBloque->id" maxlength="100" required autofocus />

        @if ($tipoBloque->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$tipoBloque::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$tipoBloque->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
