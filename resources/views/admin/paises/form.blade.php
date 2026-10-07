<x-admin.page :title="$pais->exists ? 'Editar país' : 'Nuevo país'">
    <x-admin.form
        :action="$pais->exists ? route('admin.paises.update', $pais) : route('admin.paises.store')"
        :method="$pais->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.paises.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$pais->nombre" unico="pais.nombre" :unico-ignorar="$pais->id" maxlength="100" required autofocus />
        <div class="max-w-xs">
            <x-admin.input name="codigo" label="Código ISO (2 letras)" :value="$pais->codigo" unico="pais.codigo" :unico-ignorar="$pais->id"
                maxlength="2" required class="uppercase font-mono" aria-describedby="codigo_ayuda" />
            <p id="codigo_ayuda" class="mt-1 text-xs text-gray-500 dark:text-gray-400">ISO 3166-1 alfa-2: PY, BR, AR…</p>
        </div>

        @if ($pais->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$pais::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$pais->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
