@php
    // Filas de especialidades: las del error de validación, o las que ya tiene el profesional.
    $filas = old('especialidades', $registro->exists
        ? $registro->especialidades->map(fn ($especialidad) => [
            'especialidad_id' => (string) $especialidad->id,
            'nro_matricula_especialidad' => $especialidad->pivot->nro_matricula_especialidad,
            'fecha_desde' => \App\Support\Fecha::mostrar($especialidad->pivot->fecha_desde),
            'activa' => (bool) $especialidad->pivot->activa,
        ])->all()
        : []);
    $filas = array_values(array_map(fn ($fila) => [
        'uid' => uniqid(),
        'especialidad_id' => (string) ($fila['especialidad_id'] ?? ''),
        'nro_matricula_especialidad' => $fila['nro_matricula_especialidad'] ?? '',
        'fecha_desde' => $fila['fecha_desde'] ?? '',
        'activa' => filter_var($fila['activa'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ], $filas));
    $erroresEspecialidades = collect($errors->get('especialidades*'))->flatten()->unique();
    $clases = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm';
@endphp

<x-admin.page :title="$registro->exists ? 'Editar profesional' : 'Nuevo profesional'">
    <x-admin.form
        :action="$registro->exists ? route('admin.profesionales.update', $registro) : route('admin.profesionales.store')"
        :method="$registro->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.profesionales.index')"
        width="max-w-3xl">
        @if ($registro->exists)
            <x-admin.persona-solo-lectura :persona="$registro->persona" />
        @else
            <x-admin.selector-persona :url="route('admin.profesionales.personas-disponibles')" />
        @endif

        <div class="max-w-sm">
            <x-admin.input name="matricula" label="Matrícula profesional" :value="$registro->matricula" maxlength="50" required />
        </div>

        {{-- Especialidades (profesional_especialidad): una fila por especialidad, con matrícula y fecha desde. --}}
        <div x-data="{
                filas: {{ Js::from($filas) }},
                // Una especialidad nueva propone hoy como fecha desde (se puede cambiar).
                agregar() { this.filas.push({ uid: Date.now() + Math.random(), especialidad_id: '', nro_matricula_especialidad: '', fecha_desde: {{ Js::from(\App\Support\Fecha::mostrar(\App\Support\Fecha::hoy())) }}, activa: true }) },
                quitar(i) { this.filas.splice(i, 1) },
            }" class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Especialidades</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                "Quitar" borra la especialidad (por si se cargó mal). "Deshabilitar" la conserva en el historial pero deja de
                ofrecerse como especialidad que el profesional ejerce. Los cambios se aplican al guardar.
            </p>

            {{-- Solo con JavaScript: indica que la lista viene completa (sin JS no se toca lo guardado). --}}
            <template x-if="true"><input type="hidden" name="con_especialidades" value="1"></template>

            <template x-for="(fila, i) in filas" x-bind:key="fila.uid">
                <div class="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end rounded-md border p-3"
                    x-bind:class="fila.activa ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/40'">
                    <input type="hidden" x-bind:name="`especialidades[${i}][activa]`" x-bind:value="fila.activa ? 1 : 0">
                    <div x-bind:class="fila.activa ? '' : 'opacity-60'">
                        <label x-bind:for="`especialidad_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">
                            Especialidad
                            <span x-show="! fila.activa" class="ms-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Deshabilitada</span>
                        </label>
                        <select x-bind:id="`especialidad_${i}`" x-bind:name="`especialidades[${i}][especialidad_id]`" x-model="fila.especialidad_id" required class="{{ $clases }}">
                            <option value="">Seleccionar…</option>
                            @foreach ($especialidades as $id => $nombre)
                                <option value="{{ $id }}">{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label x-bind:for="`matricula_esp_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Matrícula (opcional)</label>
                        <input type="text" maxlength="50" x-bind:id="`matricula_esp_${i}`" x-bind:name="`especialidades[${i}][nro_matricula_especialidad]`" x-model="fila.nro_matricula_especialidad" class="{{ $clases }}">
                    </div>
                    <div>
                        <label x-bind:for="`desde_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Desde</label>
                        {{-- Fecha dd/mm/aaaa con calendario (campo-fecha.js), enlazada a la fila. --}}
                        <div class="relative mt-1" x-data="campoFecha({ valor: fila.fecha_desde, max: {{ Js::from(\App\Support\Fecha::hoy()->format('Y-m-d')) }} })"
                            x-modelable="valor" x-model="fila.fecha_desde">
                            <input type="text" required x-ref="entrada" x-bind:id="`desde_${i}`" x-bind:name="`especialidades[${i}][fecha_desde]`"
                                x-bind:value="valor" x-on:input="escribir($event)"
                                placeholder="dd/mm/aaaa" inputmode="numeric" maxlength="10" autocomplete="off"
                                pattern="\d{2}/\d{2}/\d{4}" title="Fecha en formato dd/mm/aaaa"
                                class="{{ str_replace('mt-1 ', '', $clases) }} pe-10">
                            <x-admin.calendario />
                        </div>
                    </div>
                    <div class="flex gap-4 sm:mb-2">
                        <button type="button" x-on:click="fila.activa = ! fila.activa" x-text="fila.activa ? 'Deshabilitar' : 'Habilitar'"
                            class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Deshabilitar</button>
                        <button type="button" x-on:click="quitar(i)" class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Quitar</button>
                    </div>
                </div>
            </template>

            <p x-show="filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin especialidades cargadas.</p>

            <button type="button" x-on:click="agregar()" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                + Agregar especialidad
            </button>

            @if ($erroresEspecialidades->isNotEmpty())
                <x-input-error :messages="$erroresEspecialidades->all()" />
            @endif
        </div>

        @if ($registro->exists)
            <div class="max-w-sm">
                <x-admin.select name="estado_id" label="Estado" :options="$registro::estadosPermitidos()->pluck('nombre', 'id')->all()" :value="$registro->estado_id" required />
            </div>
        @endif
    </x-admin.form>
</x-admin.page>
