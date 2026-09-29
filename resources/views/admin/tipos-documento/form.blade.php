<x-admin.page :title="$tipoDocumento->exists ? 'Editar tipo de documento' : 'Nuevo tipo de documento'">
    <x-admin.form
        :action="$tipoDocumento->exists ? route('admin.tipos-documento.update', $tipoDocumento) : route('admin.tipos-documento.store')"
        :method="$tipoDocumento->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.tipos-documento.index')">
        <x-admin.input name="codigo" label="Código" :value="$tipoDocumento->codigo" maxlength="10" required autofocus />
        <x-admin.input name="nombre" label="Nombre" :value="$tipoDocumento->nombre" maxlength="50" required />

        @if ($tipoDocumento->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$tipoDocumento::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$tipoDocumento->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
