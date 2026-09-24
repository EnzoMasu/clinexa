<x-admin.page :title="$procedimiento->exists ? 'Editar procedimiento' : 'Nuevo procedimiento'">
    <x-admin.form
        :action="$procedimiento->exists ? route('admin.procedimientos.update', $procedimiento) : route('admin.procedimientos.store')"
        :method="$procedimiento->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.procedimientos.index')">
        <x-admin.input name="codigo" label="Código" :value="$procedimiento->codigo" maxlength="20" required autofocus />
        <x-admin.input name="nombre" label="Nombre" :value="$procedimiento->nombre" maxlength="150" required />
        <x-admin.select name="tipo" label="Tipo" :options="['CONSULTA', 'ESTUDIO']" :value="$procedimiento->tipo" required />
        <x-admin.input name="duracion_estimada_minutos" label="Duración estimada (minutos)" type="number" min="1"
            :value="$procedimiento->duracion_estimada_minutos" required />

        @if ($procedimiento->exists)
            <x-admin.select name="estado" label="Estado" :options="['ACTIVO', 'INACTIVO']" :value="$procedimiento->estado" required />
        @endif
    </x-admin.form>
</x-admin.page>
