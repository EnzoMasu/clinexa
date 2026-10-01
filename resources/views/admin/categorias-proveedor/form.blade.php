<x-admin.page :title="$categoriaProveedor->exists ? 'Editar categoría de proveedor' : 'Nueva categoría de proveedor'">
    <x-admin.form
        :action="$categoriaProveedor->exists ? route('admin.categorias-proveedor.update', $categoriaProveedor) : route('admin.categorias-proveedor.store')"
        :method="$categoriaProveedor->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.categorias-proveedor.index')">
        <x-admin.input name="nombre" label="Nombre" :value="$categoriaProveedor->nombre" unico="categoria_proveedor.nombre" :unico-ignorar="$categoriaProveedor->id" maxlength="100" required autofocus />

        @if ($categoriaProveedor->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$categoriaProveedor::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$categoriaProveedor->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
