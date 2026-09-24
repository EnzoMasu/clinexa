<x-admin.page :title="$tipoDocumento->exists ? 'Editar tipo de documento' : 'Nuevo tipo de documento'">
    <x-admin.form
        :action="$tipoDocumento->exists ? route('admin.tipos-documento.update', $tipoDocumento) : route('admin.tipos-documento.store')"
        :method="$tipoDocumento->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.tipos-documento.index')">
        <x-admin.input name="codigo" label="Código" :value="$tipoDocumento->codigo" maxlength="10" required autofocus />
        <x-admin.input name="nombre" label="Nombre" :value="$tipoDocumento->nombre" maxlength="50" required />
        <x-admin.select name="aplica_a" label="Aplica a" :options="['FISICA', 'JURIDICA', 'AMBOS']" :value="$tipoDocumento->aplica_a" required />

        @if ($tipoDocumento->exists)
            <x-admin.select name="estado" label="Estado" :options="['ACTIVO', 'INACTIVO']" :value="$tipoDocumento->estado" required />
        @endif
    </x-admin.form>
</x-admin.page>
