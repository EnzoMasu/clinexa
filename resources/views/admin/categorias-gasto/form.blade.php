<x-admin.page :title="$categoriaGasto->exists ? 'Editar categoría de gasto' : 'Nueva categoría de gasto'">
    <x-admin.form
        :action="$categoriaGasto->exists ? route('admin.categorias-gasto.update', $categoriaGasto) : route('admin.categorias-gasto.store')"
        :method="$categoriaGasto->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.categorias-gasto.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$categoriaGasto->nombre" unico="categoria_gasto.nombre" :unico-ignorar="$categoriaGasto->id" maxlength="100" required autofocus />

        @if ($categoriaGasto->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$categoriaGasto::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$categoriaGasto->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
