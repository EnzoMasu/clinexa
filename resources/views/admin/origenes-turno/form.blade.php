<x-admin.page :title="$origenTurno->exists ? 'Editar origen de turno' : 'Nuevo origen de turno'">
    <x-admin.form
        :action="$origenTurno->exists ? route('admin.origenes-turno.update', $origenTurno) : route('admin.origenes-turno.store')"
        :method="$origenTurno->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.origenes-turno.index')">
        <x-admin.input name="codigo" label="Código" :value="$origenTurno->codigo" unico="origen_turno.codigo" :unico-ignorar="$origenTurno->id" maxlength="20" required autofocus />
        <x-admin.input name="nombre" label="Nombre" :value="$origenTurno->nombre" maxlength="50" required />

        @if ($origenTurno->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$origenTurno::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$origenTurno->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
