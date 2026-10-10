{{--
    Anamnesis (bloques_anamnesis), grupo PREPARACIÓN. No se borran: un bloque guardado se retira y se
    repone; uno nuevo se descarta. Cada fila lleva su uid: el autoguardado devuelve el id de las nuevas
    (evento "consulta-guardada") y marca las incompletas, que todavía no se guardaron.
    Requiere $datos (App\Support\Atencion\DatosFormulario) y $clases.
--}}
@php($errores = collect($errors->get('anamnesis*'))->flatten()->unique())

<div x-data="{
        filas: {{ Js::from($datos['filasAnamnesis']) }},
        tiposActivos: {{ Js::from($datos['tiposBloque']) }},
        // Los tipos activos, más el inactivo que esta fila ya tenía.
        tiposPara(fila) { return fila.tipoInactivo ? [...this.tiposActivos, fila.tipoInactivo] : this.tiposActivos },
        agregar() { this.filas.push({ uid: 'n-' + Date.now() + Math.random(), id: '', tipo_bloque_anamnesis_id: '', contenido: '', activo: true, tipoInactivo: null, autor: null, incompleta: false }) },
        descartar(i) { this.filas.splice(i, 1) },
        guardada(detalle) {
            const ids = detalle.ids?.anamnesis ?? {};
            const incompletas = (detalle.incompletas?.anamnesis ?? []).map(String);
            this.filas.forEach((fila) => { if (ids[fila.uid]) fila.id = String(ids[fila.uid]); fila.incompleta = incompletas.includes(String(fila.uid)); });
        },
    }" x-on:consulta-guardada.window="guardada($event.detail)" class="space-y-3">
    <h3 class="font-medium text-gray-900 dark:text-gray-100">Anamnesis</h3>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Hasta {{ \App\Support\Atencion\FormularioConsulta::MAXIMO_BLOQUES }} bloques vigentes, en el orden en que se muestran.
        Un bloque guardado no se borra: se retira, y se puede reponer.
    </p>

    {{-- Solo con JavaScript: indica que la lista viene completa (sin JS no se tocan los bloques guardados). --}}
    <template x-if="true"><input type="hidden" name="con_anamnesis" value="1"></template>

    <template x-for="(fila, i) in filas" x-bind:key="fila.uid">
        <div class="grid gap-3 rounded-md border p-3 sm:grid-cols-[14rem_1fr_auto] sm:items-start"
            x-bind:class="fila.activo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/40'">
            <input type="hidden" x-bind:name="`anamnesis[${i}][uid]`" x-bind:value="fila.uid">
            <input type="hidden" x-bind:name="`anamnesis[${i}][id]`" x-bind:value="fila.id">
            <input type="hidden" x-bind:name="`anamnesis[${i}][activo]`" x-bind:value="fila.activo ? 1 : 0">
            <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                <label x-bind:for="`bloque_tipo_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">
                    Tipo
                    <span x-show="! fila.activo" class="ms-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Retirado</span>
                </label>
                <select x-bind:id="`bloque_tipo_${i}`" x-bind:name="`anamnesis[${i}][tipo_bloque_anamnesis_id]`" x-model="fila.tipo_bloque_anamnesis_id" class="{{ $clases }}">
                    <option value="">Seleccionar…</option>
                    <template x-for="tipo in tiposPara(fila)" x-bind:key="tipo.id">
                        <option x-bind:value="tipo.id" x-text="tipo.nombre" x-bind:selected="tipo.id === fila.tipo_bloque_anamnesis_id"></option>
                    </template>
                </select>
            </div>
            <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                <label x-bind:for="`bloque_contenido_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Contenido</label>
                <textarea rows="3" maxlength="5000" x-bind:id="`bloque_contenido_${i}`" x-bind:name="`anamnesis[${i}][contenido]`" x-model="fila.contenido" class="{{ $clases }}"></textarea>
                <p x-show="fila.autor" x-text="fila.autor" class="mt-1 text-xs text-gray-500 dark:text-gray-400"></p>
                <p x-show="fila.incompleta" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">{{ \App\Support\Atencion\FormularioConsulta::FILA_INCOMPLETA }}</p>
            </div>
            <div class="sm:mt-7">
                {{-- Guardado: se retira o se repone. Nuevo (todavía sin guardar): se descarta. --}}
                <button type="button" x-show="fila.id" x-on:click="fila.activo = ! fila.activo" x-text="fila.activo ? 'Retirar' : 'Reponer'"
                    class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Retirar</button>
                <button type="button" x-show="! fila.id" x-on:click="descartar(i)"
                    class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Descartar</button>
            </div>
        </div>
    </template>

    <p x-show="filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin bloques de anamnesis.</p>

    <button type="button" x-on:click="agregar()" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
        + Agregar bloque
    </button>

    @if ($errores->isNotEmpty())
        <x-input-error :messages="$errores->all()" />
    @endif
</div>
