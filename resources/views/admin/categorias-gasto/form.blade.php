<x-admin.page :title="$categoriaGasto->exists ? 'Editar categoría de gasto' : 'Nueva categoría de gasto'">
    <x-admin.form
        :action="$categoriaGasto->exists ? route('admin.categorias-gasto.update', $categoriaGasto) : route('admin.categorias-gasto.store')"
        :method="$categoriaGasto->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.categorias-gasto.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$categoriaGasto->nombre" maxlength="100" required autofocus />
    </x-admin.form>
</x-admin.page>
