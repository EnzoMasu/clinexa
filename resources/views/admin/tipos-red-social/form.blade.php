<x-admin.page :title="$tipoRedSocial->exists ? 'Editar tipo de red social' : 'Nuevo tipo de red social'">
    <x-admin.form
        :action="$tipoRedSocial->exists ? route('admin.tipos-red-social.update', $tipoRedSocial) : route('admin.tipos-red-social.store')"
        :method="$tipoRedSocial->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.tipos-red-social.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$tipoRedSocial->nombre" unico="tipo_red_social.nombre" :unico-ignorar="$tipoRedSocial->id" maxlength="100" required autofocus />

        @if ($tipoRedSocial->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$tipoRedSocial::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$tipoRedSocial->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
