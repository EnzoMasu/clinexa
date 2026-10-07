<x-admin.page :title="$departamento->exists ? 'Editar departamento' : 'Nuevo departamento'">
    <x-admin.form
        :action="$departamento->exists ? route('admin.departamentos.update', $departamento) : route('admin.departamentos.store')"
        :method="$departamento->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.departamentos.index')">
        <x-admin.select name="pais_id" label="País" :options="$paises" :value="$departamento->pais_id" required />
        <x-admin.input name="nombre" label="Nombre" :value="$departamento->nombre" maxlength="100" required autofocus
            unico="departamento.nombre" :unico-ignorar="$departamento->id" :unico-con="['pais_id']" />

        @if ($departamento->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$departamento::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$departamento->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
