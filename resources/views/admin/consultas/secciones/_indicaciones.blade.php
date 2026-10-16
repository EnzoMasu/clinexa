{{--
    Indicaciones generales (grupo CLÍNICO): salen en la hoja de la receta. No se borran: se retiran y se
    reponen. Cada fila lleva su uid (ver _anamnesis). Requiere $datos y $clases.
    "Insertar dieta" COPIA el texto de una dieta activa del catálogo al final de la indicación: no queda enlace, así que
    cambiar la dieta después no altera lo guardado (docs/indicaciones-generales.md). El botón cuenta como cambio (autoguardado).
--}}
@php($errores = collect($errors->get('indicaciones*'))->flatten()->unique())

<div x-data="{
        filas: {{ Js::from($datos['filasIndicaciones']) }},
        tiposActivos: {{ Js::from($datos['tiposIndicacion']) }},
        dietas: {{ Js::from($datos['dietas']) }},
        maximo: 500,
        // Los tipos activos, más el inactivo que esta fila ya tenía.
        tiposPara(fila) { return fila.tipoInactivo ? [...this.tiposActivos, fila.tipoInactivo] : this.tiposActivos },
        agregar() { this.filas.push({ uid: 'n-' + Date.now() + Math.random(), id: '', tipo_indicacion_id: '', descripcion: '', activo: true, tipoInactivo: null, incompleta: false }) },
        // Copia el texto de la dieta elegida al final de la indicación (en una línea aparte). Si no entra, no la toca y avisa.
        insertarDieta(fila) {
            fila.avisoDieta = '';
            const dieta = this.dietas.find((d) => d.id === fila.dietaElegida);
            if (! dieta) { fila.avisoDieta = 'Elija una dieta.'; return; }
            const actual = (fila.descripcion ?? '').trimEnd();
            const nuevo = actual === '' ? dieta.texto : actual + '\n' + dieta.texto;
            if (nuevo.length > this.maximo) { fila.avisoDieta = 'La dieta no entra: la indicación admite hasta ' + this.maximo + ' caracteres.'; return; }
            fila.descripcion = nuevo;
            fila.dietaElegida = '';
        },
        descartar(i) { this.filas.splice(i, 1) },
        guardada(detalle) {
            const ids = detalle.ids?.indicaciones ?? {};
            const incompletas = (detalle.incompletas?.indicaciones ?? []).map(String);
            this.filas.forEach((fila) => { if (ids[fila.uid]) fila.id = String(ids[fila.uid]); fila.incompleta = incompletas.includes(String(fila.uid)); });
        },
    }" x-on:consulta-guardada.window="guardada($event.detail)" class="space-y-3">
    <h3 class="font-medium text-gray-900 dark:text-gray-100">Indicaciones generales</h3>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Reposo, dieta, control… Salen en la hoja de la receta, para el paciente. Hasta {{ \App\Support\Atencion\FormularioConsulta::MAXIMO_INDICACIONES }}
        vigentes, en el orden en que se muestran. Una indicación guardada no se borra: se retira, y se puede reponer.
    </p>

    {{-- Solo con JavaScript: indica que la lista viene completa (sin JS no se tocan las indicaciones guardadas). --}}
    <template x-if="true"><input type="hidden" name="con_indicaciones" value="1"></template>

    <template x-for="(fila, i) in filas" x-bind:key="fila.uid">
        <div class="grid gap-3 rounded-md border p-3 sm:grid-cols-[12rem_1fr_auto] sm:items-start"
            x-bind:class="fila.activo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/40'">
            <input type="hidden" x-bind:name="`indicaciones[${i}][uid]`" x-bind:value="fila.uid">
            <input type="hidden" x-bind:name="`indicaciones[${i}][id]`" x-bind:value="fila.id">
            <input type="hidden" x-bind:name="`indicaciones[${i}][activo]`" x-bind:value="fila.activo ? 1 : 0">
            <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                <label x-bind:for="`indicacion_tipo_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">
                    Tipo (opcional)
                    <span x-show="! fila.activo" class="ms-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Retirado</span>
                </label>
                <select x-bind:id="`indicacion_tipo_${i}`" x-bind:name="`indicaciones[${i}][tipo_indicacion_id]`" x-model="fila.tipo_indicacion_id" class="{{ $clases }}">
                    <option value="">Sin tipo</option>
                    <template x-for="tipo in tiposPara(fila)" x-bind:key="tipo.id">
                        <option x-bind:value="tipo.id" x-text="tipo.nombre" x-bind:selected="tipo.id === fila.tipo_indicacion_id"></option>
                    </template>
                </select>
            </div>
            <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                <label x-bind:for="`indicacion_descripcion_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Indicación</label>
                <textarea rows="2" maxlength="500" x-bind:id="`indicacion_descripcion_${i}`" x-bind:name="`indicaciones[${i}][descripcion]`" x-model="fila.descripcion" class="{{ $clases }}"></textarea>
                <p x-show="fila.incompleta" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">{{ \App\Support\Atencion\FormularioConsulta::FILA_INCOMPLETA }}</p>
                @if ($datos['dietas'])
                    {{-- Sin name: la dieta elegida no se envía; solo se copia su texto. change.stop: elegir no es un cambio. --}}
                    <div x-show="fila.activo" class="mt-2 flex flex-wrap items-center gap-2" data-insertar-dieta>
                        <label x-bind:for="`indicacion_dieta_${i}`" class="sr-only">Dieta para insertar</label>
                        <select x-bind:id="`indicacion_dieta_${i}`" x-model="fila.dietaElegida" x-on:change.stop x-on:input.stop
                            class="rounded-md border-gray-300 py-1 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">Elegir dieta…</option>
                            <template x-for="dieta in dietas" x-bind:key="dieta.id">
                                <option x-bind:value="dieta.id" x-text="dieta.nombre"></option>
                            </template>
                        </select>
                        <button type="button" x-on:click="insertarDieta(fila)"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Insertar dieta</button>
                        <p x-show="fila.avisoDieta" x-text="fila.avisoDieta" class="w-full text-xs font-medium text-amber-700 dark:text-amber-300"></p>
                    </div>
                @endif
            </div>
            <div class="sm:mt-7">
                {{-- Guardada: se retira o se repone. Nueva (todavía sin guardar): se descarta. --}}
                <button type="button" x-show="fila.id" x-on:click="fila.activo = ! fila.activo" x-text="fila.activo ? 'Retirar' : 'Reponer'"
                    class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Retirar</button>
                <button type="button" x-show="! fila.id" x-on:click="descartar(i)"
                    class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Descartar</button>
            </div>
        </div>
    </template>

    <p x-show="filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin indicaciones generales.</p>

    <button type="button" x-on:click="agregar()" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
        + Agregar indicación
    </button>

    @if ($errores->isNotEmpty())
        <x-input-error :messages="$errores->all()" />
    @endif
</div>
