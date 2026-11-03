@php
    $tipoInicial = old('tipo_persona', $persona->tipo_persona);
@endphp

<x-admin.page :title="$persona->exists ? 'Editar persona' : 'Nueva persona'">
    <x-admin.form
        :action="$persona->exists ? route('admin.personas.update', $persona) : route('admin.personas.store')"
        :method="$persona->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.personas.index')"
        width="max-w-3xl"
        x-data="{ tipo: {{ Js::from($tipoInicial) }} }">

        @include('admin.personas._campos')

        @if ($persona->exists)
            <div class="grid gap-6 sm:grid-cols-2">
                <x-admin.select name="estado_id" label="Estado" :options="$persona::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$persona->estado_id" required />
            </div>
        @endif
    </x-admin.form>
</x-admin.page>
