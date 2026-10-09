@php
    use App\Http\Controllers\Admin\RecetaController;
    use App\Models\DetalleReceta;

    $paciente = $consulta->historiaClinica->paciente;

    // Renglones: los del error de validación, o los guardados en su orden. Uno vacío en una receta nueva.
    $vacio = ['id' => '', 'medicamento' => '', 'cantidad' => '', 'dosis' => '', 'via' => '', 'frecuencia' => '', 'duracion' => '', 'observaciones' => ''];
    $guardados = $receta->exists ? $receta->detalles->sortBy(['orden', 'id'])->map(fn ($detalle) => ['id' => (string) $detalle->id, ...$detalle->only(array_keys(DetalleReceta::CAMPOS))])->values()->all() : [];
    $filas = collect(old('con_detalles') ? (array) old('detalles', []) : ($guardados ?: [$vacio]))
        ->map(fn ($fila) => ['uid' => uniqid('', true), ...collect($vacio)->map(fn ($valor, $campo) => (string) ($fila[$campo] ?? ''))->all()])
        ->values()->all();

    $errores = collect($errors->get('detalles*'))->flatten()->unique();
    $clases = 'mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm text-sm';
    // Campo => [etiqueta, máximo, obligatorio, ancho en la grilla].
    $campos = [
        'medicamento' => ['Medicamento (nombre comercial)', 150, true, 'sm:col-span-4'],
        'cantidad' => ['Cantidad', 100, false, 'sm:col-span-2'],
        'dosis' => ['Dosis', 100, true, 'sm:col-span-2'],
        'via' => ['Vía', 50, false, 'sm:col-span-1'],
        'frecuencia' => ['Frecuencia', 100, true, 'sm:col-span-2'],
        'duracion' => ['Duración', 100, false, 'sm:col-span-1'],
        'observaciones' => ['Observaciones', 200, false, 'sm:col-span-6'],
    ];
@endphp

<x-admin.page :title="$receta->exists ? 'Editar borrador de receta' : 'Nueva receta'">
    <x-admin.form
        :action="$receta->exists ? route('admin.recetas.update', $receta) : route('admin.recetas.store', $consulta)"
        :method="$receta->exists ? 'PUT' : 'POST'"
        :cancel="route('admin.consultas.show', $consulta)"
        width="max-w-5xl">
        @if ($receta->exists)
            {{-- Control de concurrencia: si otra ventana guardó después de abrir esta, se rechaza. --}}
            <input type="hidden" name="version" value="{{ $receta->version() }}">
        @endif

        <dl class="grid gap-4 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-700 dark:bg-gray-900/40 sm:grid-cols-3">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Paciente</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $paciente->persona->nombre_completo }}</dd>
                <dd class="text-xs text-gray-500 dark:text-gray-400">Ficha {{ $paciente->nro_ficha }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Consulta</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $consulta->fechaHoraTexto() }}</dd>
                <dd class="text-xs text-gray-500 dark:text-gray-400">{{ $consulta->profesional->persona->nombre_completo }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Estado</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">Borrador</dd>
                @if ($receta->reemplazaA)
                    <dd class="text-xs text-gray-500 dark:text-gray-400">Reemplaza a {{ $receta->reemplazaA->numero }}</dd>
                @endif
            </div>
        </dl>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Escriba cada medicamento una sola vez, con su dosis, vía, frecuencia, duración y observaciones: con eso se arman las dos
            mitades de la hoja (la receta y las indicaciones para el paciente). Entre {{ RecetaController::MINIMO_RENGLONES }} y
            {{ RecetaController::MAXIMO_RENGLONES }} medicamentos, y todo tiene que entrar en media hoja. Las indicaciones generales
            (reposo, dieta, control…) se cargan en la consulta.
        </p>

        <div x-data="{
                filas: {{ Js::from($filas) }},
                maximo: {{ RecetaController::MAXIMO_RENGLONES }},
                agregar() { if (this.filas.length < this.maximo) this.filas.push({ uid: Date.now() + Math.random(), id: '', medicamento: '', cantidad: '', dosis: '', via: '', frecuencia: '', duracion: '', observaciones: '' }) },
                quitar(i) { this.filas.splice(i, 1) },
            }" class="space-y-3">
            <h3 class="font-medium text-gray-900 dark:text-gray-100">Medicamentos</h3>

            {{-- Solo con JavaScript: indica que la lista viene completa (sin JS no se tocan los renglones guardados). --}}
            <template x-if="true"><input type="hidden" name="con_detalles" value="1"></template>

            <template x-for="(fila, i) in filas" x-bind:key="fila.uid">
                <div class="space-y-2 rounded-md border border-gray-200 p-3 dark:border-gray-700">
                    <input type="hidden" x-bind:name="`detalles[${i}][id]`" x-bind:value="fila.id">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400" x-text="`Medicamento ${i + 1}`"></span>
                        <button type="button" x-on:click="quitar(i)" class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Quitar</button>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-6">
                        @foreach ($campos as $campo => [$etiqueta, $maximo, $obligatorio, $ancho])
                            <div class="{{ $ancho }}">
                                <label x-bind:for="`detalle_{{ $campo }}_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">{{ $etiqueta }}</label>
                                <input type="text" maxlength="{{ $maximo }}" @if ($obligatorio) required @endif autocomplete="off"
                                    x-bind:id="`detalle_{{ $campo }}_${i}`" x-bind:name="`detalles[${i}][{{ $campo }}]`" x-model="fila.{{ $campo }}" class="{{ $clases }}">
                            </div>
                        @endforeach
                    </div>
                </div>
            </template>

            <p x-show="filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin medicamentos: agregue al menos uno.</p>

            <button type="button" x-on:click="agregar()" x-bind:disabled="filas.length >= maximo"
                class="text-sm font-medium text-indigo-600 hover:text-indigo-900 disabled:opacity-40 dark:text-indigo-400 dark:hover:text-indigo-300">
                + Agregar medicamento
            </button>

            @if ($errores->isNotEmpty())
                <x-input-error :messages="$errores->all()" />
            @endif
        </div>

        <x-admin.textarea name="observaciones" label="Observaciones de la receta (opcional)" :value="$receta->observaciones" rows="2" maxlength="300" />

        <noscript><p class="text-sm text-red-600">Para cargar los medicamentos se necesita JavaScript habilitado. Sin JavaScript se guardan las observaciones, y los medicamentos ya guardados no se pierden.</p></noscript>
    </x-admin.form>
</x-admin.page>
