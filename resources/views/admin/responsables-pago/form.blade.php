<x-admin.page :title="$registro->exists ? 'Editar responsable de pago' : 'Nuevo responsable de pago'">
    <x-admin.form
        :action="$registro->exists ? route('admin.responsables-pago.update', $registro) : route('admin.responsables-pago.store')"
        :method="$registro->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.responsables-pago.index')">
        @if ($registro->exists)
            <x-admin.persona-solo-lectura :persona="$registro->persona" />
        @else
            <x-admin.selector-persona :url="route('admin.responsables-pago.personas-disponibles')" />
        @endif

        <x-admin.input name="limite_credito" label="Límite de crédito (opcional, en guaraníes)" type="number" min="0" step="1" :value="$registro->limite_credito !== null ? (float) $registro->limite_credito : null" />

        @if ($registro->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$registro::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$registro->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
