@php
    use App\Models\Persona;

    $tipoInicial = old('tipo_persona', $persona->tipo_persona);
    $tipoDocumentoActual = (string) old('tipo_documento_id', $persona->tipo_documento_id);
@endphp

<x-admin.page :title="$persona->exists ? 'Editar persona' : 'Nueva persona'">
    <x-admin.form
        :action="$persona->exists ? route('admin.personas.update', $persona) : route('admin.personas.store')"
        :method="$persona->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.personas.index')"
        width="max-w-3xl"
        x-data="{ tipo: {{ Js::from($tipoInicial) }} }">

        <div class="grid gap-6 sm:grid-cols-2">
            {{-- Al cambiar el tipo, si el documento elegido ya no aplica, se limpia. --}}
            <x-admin.select name="tipo_persona" label="Tipo de persona" :options="['FISICA' => 'Física', 'JURIDICA' => 'Jurídica']"
                :value="$tipoInicial" x-model="tipo" required
                x-on:change="$nextTick(() => { if ($refs.tipoDocumento.selectedOptions[0]?.disabled) $refs.tipoDocumento.value = '' })" />

            <x-admin.select name="tipo_documento_id" label="Tipo de documento" :value="$tipoDocumentoActual" x-ref="tipoDocumento" required>
                @foreach ($tiposDocumento as $tipoDocumento)
                    @php($noAplica = "! ['AMBOS', tipo].includes('{$tipoDocumento->aplica_a}')")
                    <option value="{{ $tipoDocumento->id }}" x-bind:disabled="{{ $noAplica }}" x-bind:hidden="{{ $noAplica }}"
                        @selected($tipoDocumentoActual === (string) $tipoDocumento->id)>
                        {{ $tipoDocumento->codigo }} — {{ $tipoDocumento->nombre }}
                    </option>
                @endforeach
            </x-admin.select>

            <x-admin.input name="nro_documento" label="Número de documento" :value="$persona->nro_documento" maxlength="20" required />
        </div>

        {{-- fieldset deshabilitado = sus campos no se validan en el navegador ni se envían --}}
        <fieldset x-show="tipo === 'FISICA'" x-bind:disabled="tipo !== 'FISICA'"
            @disabled($tipoInicial !== 'FISICA') @style(['display: none' => $tipoInicial !== 'FISICA']) class="space-y-4">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Datos personales</h3>
            <div class="grid gap-6 sm:grid-cols-2">
                <x-admin.input name="apellidos" label="Apellidos" :value="$persona->apellidos" maxlength="100" required />
                <x-admin.input name="nombres" label="Nombres" :value="$persona->nombres" maxlength="100" required />
                <x-admin.input name="fecha_nacimiento" label="Fecha de nacimiento" type="date"
                    :value="$persona->fecha_nacimiento?->format('Y-m-d')" :max="now()->format('Y-m-d')" required />
                <x-admin.select name="sexo" label="Sexo" :options="Persona::SEXOS" :value="$persona->sexo" nullable />
                <x-admin.input name="nacionalidad" label="Nacionalidad" :value="$persona->nacionalidad" maxlength="50" />
                <x-admin.select name="estado_civil" label="Estado civil" :options="Persona::ESTADOS_CIVILES" :value="$persona->estado_civil" nullable />
            </div>
        </fieldset>

        <fieldset x-show="tipo === 'JURIDICA'" x-bind:disabled="tipo !== 'JURIDICA'"
            @disabled($tipoInicial !== 'JURIDICA') @style(['display: none' => $tipoInicial !== 'JURIDICA']) class="space-y-4">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Datos de la empresa</h3>
            <div class="grid gap-6 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-admin.input name="razon_social" label="Razón social" :value="$persona->razon_social" maxlength="150" required />
                </div>
                <x-admin.input name="nombre_fantasia" label="Nombre de fantasía" :value="$persona->nombre_fantasia" maxlength="150" />
                <x-admin.input name="representante_legal" label="Representante legal" :value="$persona->representante_legal" maxlength="150" />
            </div>
        </fieldset>

        <div class="space-y-4">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Contacto</h3>
            <div class="grid gap-6 sm:grid-cols-2">
                <x-admin.input name="email" label="Email" type="email" :value="$persona->email" maxlength="100" required />
                <x-admin.input name="telefono" label="Teléfono" :value="$persona->telefono" maxlength="20" required />
                <div class="sm:col-span-2">
                    <x-admin.input name="direccion" label="Dirección" :value="$persona->direccion" maxlength="200" required />
                </div>
            </div>
        </div>

        @if ($persona->exists)
            <div class="grid gap-6 sm:grid-cols-2">
                <x-admin.select name="estado" label="Estado" :options="['ACTIVO', 'INACTIVO']" :value="$persona->estado" required />
            </div>
        @endif
    </x-admin.form>
</x-admin.page>
