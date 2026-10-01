@php
    // Categorías tildadas: las del error de validación, o las que ya tiene el proveedor.
    $tildadas = array_map('intval', old('con_categorias') ? (array) old('categorias', []) : $registro->categorias->modelKeys());
@endphp

<x-admin.page :title="$registro->exists ? 'Editar proveedor' : 'Nuevo proveedor'">
    <x-admin.form
        :action="$registro->exists ? route('admin.proveedores.update', $registro) : route('admin.proveedores.store')"
        :method="$registro->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.proveedores.index')">
        @if ($registro->exists)
            <x-admin.persona-solo-lectura :persona="$registro->persona" />
        @else
            <x-admin.selector-persona :url="route('admin.proveedores.personas-disponibles')" />
        @endif

        {{-- Categorías (proveedor_categoria): puede tener varias. --}}
        <fieldset class="space-y-2">
            <legend class="font-medium text-sm text-gray-700 dark:text-gray-300">Categorías</legend>
            <input type="hidden" name="con_categorias" value="1">
            @forelse ($categorias as $categoria)
                <label class="flex items-center gap-2 text-sm text-gray-900 dark:text-gray-100">
                    <input type="checkbox" name="categorias[]" value="{{ $categoria->id }}" @checked(in_array($categoria->id, $tildadas, true))
                        class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800">
                    {{ $categoria->nombre }}
                    @unless ($categoria->estaActivo())
                        <x-admin.estado-badge :estado="$categoria->estado" />
                    @endunless
                </label>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No hay categorías de proveedor cargadas.</p>
            @endforelse
            <x-input-error :messages="[...$errors->get('categorias'), ...$errors->get('categorias.*')]" />
        </fieldset>

        <x-admin.textarea name="condiciones_comerciales" label="Condiciones comerciales (opcional)" :value="$registro->condiciones_comerciales" maxlength="5000" />
        <x-admin.input name="datos_bancarios" label="Datos bancarios (opcional)" :value="$registro->datos_bancarios" maxlength="255"
            placeholder="Banco, tipo y número de cuenta" />

        @if ($registro->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$registro::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$registro->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
