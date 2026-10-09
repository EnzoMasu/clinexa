{{--
    Motivo de consulta y diagnósticos CIE-10 (grupo CLÍNICO). Los diagnósticos no se borran: uno guardado
    se retira y se repone. Cada fila lleva su uid (ver _anamnesis). Requiere $datos, $clases y $consulta.
--}}
@use('App\Support\BuscadorPersonas')
@php($errores = collect([...$errors->get('diagnosticos*'), ...$errors->get('diagnostico_principal')])->flatten()->unique())

<x-admin.textarea name="motivo_consulta" label="Motivo de consulta" :value="$consulta->motivo_consulta" rows="3" maxlength="500" />

<div x-data="{
        filas: {{ Js::from($datos['filasDiagnosticos']) }},
        tipos: {{ Js::from($datos['tiposDiagnostico']) }},
        principal: null,
        init() { this.principal = this.filas.find((fila) => fila.principal)?.uid ?? null },
        agregar() {
            this.filas.push({ uid: 'n-' + Date.now() + Math.random(), id: '', codigo_cie10: '', texto: '', tipo: 'PRESUNTIVO', descripcion_adicional: '', activo: true, principal: false, incompleta: false });
            // El primero vigente queda como principal.
            if (! this.filas.some((fila) => fila.activo && fila.uid === this.principal)) { this.principal = this.filas[this.filas.length - 1].uid }
        },
        descartar(i) { this.filas.splice(i, 1) },
        guardada(detalle) {
            const ids = detalle.ids?.diagnosticos ?? {};
            const incompletas = (detalle.incompletas?.diagnosticos ?? []).map(String);
            this.filas.forEach((fila) => { if (ids[fila.uid]) fila.id = String(ids[fila.uid]); fila.incompleta = incompletas.includes(String(fila.uid)); });
        },
    }" x-on:consulta-guardada.window="guardada($event.detail)" class="space-y-3">
    <h3 class="font-medium text-gray-900 dark:text-gray-100">Diagnósticos</h3>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Hasta {{ \App\Support\Atencion\FormularioConsulta::MAXIMO_DIAGNOSTICOS }} diagnósticos vigentes, sin repetir el código; uno de ellos es el principal.
        Un diagnóstico guardado no se borra: se retira, y se puede reponer.
    </p>

    <template x-if="true"><input type="hidden" name="con_diagnosticos" value="1"></template>

    <template x-for="(fila, i) in filas" x-bind:key="fila.uid">
        <div class="space-y-3 rounded-md border p-3"
            x-bind:class="fila.activo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/40'">
            <input type="hidden" x-bind:name="`diagnosticos[${i}][uid]`" x-bind:value="fila.uid">
            <input type="hidden" x-bind:name="`diagnosticos[${i}][id]`" x-bind:value="fila.id">
            <input type="hidden" x-bind:name="`diagnosticos[${i}][activo]`" x-bind:value="fila.activo ? 1 : 0">
            <input type="hidden" x-bind:name="`diagnosticos[${i}][codigo_cie10]`" x-bind:value="fila.codigo_cie10">

            <div class="grid gap-3 sm:grid-cols-[1fr_12rem_auto] sm:items-start" x-bind:class="fila.activo ? '' : 'opacity-60'">
                {{-- Código CIE-10: buscador (resources/js/buscador-cie10.js). --}}
                <div x-data="buscadorCie10({ url: @js(route('admin.historias-clinicas.cie10')), minimo: {{ BuscadorPersonas::MINIMO }} })">
                    <label x-bind:for="`diagnostico_buscar_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">
                        CIE-10
                        <span x-show="! fila.activo" class="ms-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Retirado</span>
                    </label>
                    <div x-show="fila.codigo_cie10" class="mt-1 flex items-center justify-between gap-3 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm dark:border-indigo-800 dark:bg-indigo-900/30">
                        <span class="font-medium text-gray-900 dark:text-gray-100" x-text="fila.texto"></span>
                        <button type="button" x-show="! fila.id" x-on:click="cambiar()" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Cambiar</button>
                    </div>
                    <div x-show="! fila.codigo_cie10">
                        <input type="search" x-bind:id="`diagnostico_buscar_${i}`" x-model="q" x-on:input.debounce.350ms="buscar()"
                            placeholder="Buscar por código o descripción…" autocomplete="off" class="{{ $clases }}">
                        <ul class="mt-1 max-h-56 overflow-y-auto rounded-md border border-gray-200 divide-y divide-gray-100 dark:border-gray-700 dark:divide-gray-700"
                            x-show="cargando || buscado" x-bind:aria-busy="cargando.toString()">
                            <template x-for="cie10 in resultados" x-bind:key="cie10.codigo">
                                <li>
                                    <button type="button" x-on:click="elegir(cie10)" class="block w-full px-3 py-2 text-left text-sm text-gray-800 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
                                        <span class="font-mono font-medium" x-text="cie10.codigo"></span> — <span x-text="cie10.descripcion"></span>
                                    </button>
                                </li>
                            </template>
                            <li x-show="buscado && ! cargando && resultados.length === 0" class="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">No hay códigos activos con esa búsqueda.</li>
                            <li x-show="cargando" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400" role="status">Buscando…</li>
                        </ul>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Escriba al menos {{ BuscadorPersonas::MINIMO }} caracteres. Se muestran hasta {{ BuscadorPersonas::LIMITE }} códigos activos: si no aparece, afine la búsqueda.
                        </p>
                    </div>
                    <p x-show="fila.incompleta" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">{{ \App\Support\Atencion\FormularioConsulta::FILA_INCOMPLETA }}</p>
                </div>

                <div>
                    <label x-bind:for="`diagnostico_tipo_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Tipo</label>
                    <select x-bind:id="`diagnostico_tipo_${i}`" x-bind:name="`diagnosticos[${i}][tipo]`" x-model="fila.tipo" class="{{ $clases }}">
                        <template x-for="tipo in tipos" x-bind:key="tipo.valor">
                            <option x-bind:value="tipo.valor" x-text="tipo.nombre" x-bind:selected="tipo.valor === fila.tipo"></option>
                        </template>
                    </select>
                </div>

                <div class="flex flex-col gap-2 sm:mt-7">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="radio" name="diagnostico_principal" x-bind:value="i" x-bind:checked="principal === fila.uid" x-on:change="principal = fila.uid"
                            x-bind:disabled="! fila.activo" class="border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900">
                        Principal
                    </label>
                    <button type="button" x-show="fila.id" x-on:click="fila.activo = ! fila.activo" x-text="fila.activo ? 'Retirar' : 'Reponer'"
                        class="text-left text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Retirar</button>
                    <button type="button" x-show="! fila.id" x-on:click="descartar(i)"
                        class="text-left text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Descartar</button>
                </div>
            </div>

            <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                <label x-bind:for="`diagnostico_descripcion_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Descripción adicional (opcional)</label>
                <input type="text" maxlength="500" x-bind:id="`diagnostico_descripcion_${i}`" x-bind:name="`diagnosticos[${i}][descripcion_adicional]`" x-model="fila.descripcion_adicional" class="{{ $clases }}">
            </div>
        </div>
    </template>

    <p x-show="filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin diagnósticos.</p>

    <button type="button" x-on:click="agregar()" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
        + Agregar diagnóstico
    </button>

    @if ($errores->isNotEmpty())
        <x-input-error :messages="$errores->all()" />
    @endif
</div>
