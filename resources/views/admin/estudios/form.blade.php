<x-admin.page :title="$estudio->exists ? 'Editar estudio' : 'Nuevo estudio'">
    <x-admin.form
        :action="$estudio->exists ? route('admin.estudios.update', $estudio) : route('admin.estudios.store')"
        :method="$estudio->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.estudios.index')">
        <div>
            <x-admin.input name="nombre" label="Nombre" :value="$estudio->nombre" unico="estudio.nombre" :unico-ignorar="$estudio->id" maxlength="{{ \App\Models\Estudio::MAXIMO_NOMBRE }}" required autofocus />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                El nombre se copia a cada orden de estudio al emitirla; los cambios posteriores no alteran las órdenes ya emitidas.
            </p>
        </div>

        @if ($estudio->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$estudio::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$estudio->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
