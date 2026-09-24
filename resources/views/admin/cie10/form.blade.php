<x-admin.page :title="$cie10->exists ? 'Editar código CIE-10' : 'Nuevo código CIE-10'">
    <x-admin.form
        :action="$cie10->exists ? route('admin.cie10.update', $cie10) : route('admin.cie10.store')"
        :method="$cie10->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.cie10.index')">
        {{-- El código es la PK: solo se carga al crear. --}}
        @if ($cie10->exists)
            <x-admin.input name="codigo" label="Código" :value="$cie10->codigo" readonly disabled />
        @else
            <x-admin.input name="codigo" label="Código" maxlength="10" placeholder="Ej: J06.9" required autofocus />
        @endif
        <x-admin.input name="descripcion" label="Descripción" :value="$cie10->descripcion" maxlength="255" required />
        <x-admin.input name="capitulo" label="Capítulo" :value="$cie10->capitulo" maxlength="100" required />
    </x-admin.form>
</x-admin.page>
