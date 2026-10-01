<x-admin.page :title="$especialidad->exists ? 'Editar especialidad' : 'Nueva especialidad'">
    <x-admin.form
        :action="$especialidad->exists ? route('admin.especialidades.update', $especialidad) : route('admin.especialidades.store')"
        :method="$especialidad->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.especialidades.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$especialidad->nombre" unico="especialidad.nombre" :unico-ignorar="$especialidad->id" maxlength="100" required autofocus />
        <x-admin.textarea name="descripcion" label="Descripción" :value="$especialidad->descripcion" />

        @if ($especialidad->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$especialidad::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$especialidad->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
