<x-admin.page :title="$consultorio->exists ? 'Editar consultorio' : 'Nuevo consultorio'">
    <x-admin.form
        :action="$consultorio->exists ? route('admin.consultorios.update', $consultorio) : route('admin.consultorios.store')"
        :method="$consultorio->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.consultorios.index')">
        <x-admin.select name="sucursal_id" label="Sucursal" :options="$sucursales" :value="$consultorio->sucursal_id" required />
        <x-admin.input name="nombre" label="Nombre" :value="$consultorio->nombre" maxlength="100" required autofocus
            unico="consultorio.nombre" :unico-ignorar="$consultorio->id" :unico-con="['sucursal_id']" />

        @if ($consultorio->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$consultorio::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$consultorio->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
