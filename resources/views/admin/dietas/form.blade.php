<x-admin.page :title="$dieta->exists ? 'Editar dieta' : 'Nueva dieta'">
    <x-admin.form
        :action="$dieta->exists ? route('admin.dietas.update', $dieta) : route('admin.dietas.store')"
        :method="$dieta->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.dietas.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$dieta->nombre" unico="dieta.nombre" :unico-ignorar="$dieta->id" maxlength="100" required autofocus />

        <div>
            <x-admin.textarea name="texto" label="Texto" :value="$dieta->texto" rows="6" maxlength="{{ \App\Models\Dieta::MAXIMO_TEXTO }}" required />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Hasta {{ \App\Models\Dieta::MAXIMO_TEXTO }} caracteres. Se copia tal cual a la indicación general; los cambios
                posteriores no alteran las indicaciones ya guardadas.
            </p>
        </div>

        @if ($dieta->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$dieta::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$dieta->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
