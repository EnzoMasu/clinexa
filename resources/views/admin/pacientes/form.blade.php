<x-admin.page :title="$registro->exists ? 'Editar paciente' : 'Nuevo paciente'">
    <x-admin.form
        :action="$registro->exists ? route('admin.pacientes.update', $registro) : route('admin.pacientes.store')"
        :method="$registro->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.pacientes.index')">
        @if ($registro->exists)
            <x-admin.persona-solo-lectura :persona="$registro->persona" />
        @else
            <x-admin.selector-persona :url="route('admin.pacientes.personas-disponibles')" />
        @endif

        <x-admin.input name="nro_ficha" label="Número de ficha" :value="$registro->nro_ficha" maxlength="20" required aria-describedby="nro_ficha_ayuda" />
        @unless ($registro->exists)
            <p id="nro_ficha_ayuda" class="-mt-4 text-xs text-gray-500 dark:text-gray-400">Se propone el siguiente número libre (formato FP-0000001); se puede cambiar (por ejemplo, para respetar el número de una ficha en papel). La fecha de alta se registra sola al guardar.</p>
        @endunless

        {{-- fecha_alta: se completa sola al crear y no se edita. --}}
        @if ($registro->exists)
            <div class="text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-300">Fecha de alta:</span>
                <span class="text-gray-900 dark:text-gray-100">{{ $registro->fecha_alta->format('d/m/Y') }}</span>
            </div>
        @endif

        @if ($registro->exists)
            <x-admin.select name="estado_id" label="Estado" :options="$registro::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$registro->estado_id" required />
        @endif
    </x-admin.form>
</x-admin.page>
