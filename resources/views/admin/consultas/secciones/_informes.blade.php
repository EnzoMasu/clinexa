{{--
    Informes de estudio (grupo CLÍNICO; docs/informes-estudio.md): el informe de un estudio hecho en la clínica, en texto
    libre. El estudio se elige del catálogo o se escribe a mano (no se agrega al catálogo); el solicitante es opcional. No se
    borran: se retiran y se reponen. Cada fila lleva su uid (ver _anamnesis). Requiere $datos y $clases.
    El PDF de cada informe sale de lo YA guardado: el enlace aparece cuando la fila tiene id (solo a quien puede generarlo).
--}}
@php
    $errores = collect($errors->get('informes*'))->flatten()->unique();
    $puedePdf = \Illuminate\Support\Facades\Gate::allows('generarPdf', [$consulta, \App\Support\PdfClinico\PdfInformeEstudio::DOCUMENTO]);
@endphp

<div x-data="{
        filas: {{ Js::from($datos['filasInformes']) }},
        estudios: {{ Js::from($datos['estudios']) }},
        urlPdf: {{ Js::from($puedePdf ? route('admin.pdf.informe-estudio', ['consulta' => $consulta, 'informe' => '__ID__']) : null) }},
        // Los estudios activos, más el inactivo que esta fila ya tenía.
        estudiosPara(fila) { return fila.estudioInactivo ? [...this.estudios, fila.estudioInactivo] : this.estudios },
        agregar() { this.filas.push({ uid: 'n-' + Date.now() + Math.random(), id: '', eleccion: '', estudio_libre: '', solicitante: '', texto: '', activo: true, estudioInactivo: null, autor: null, incompleta: false }) },
        descartar(i) { this.filas.splice(i, 1) },
        guardada(detalle) {
            const ids = detalle.ids?.informes ?? {};
            const incompletas = (detalle.incompletas?.informes ?? []).map(String);
            this.filas.forEach((fila) => { if (ids[fila.uid]) fila.id = String(ids[fila.uid]); fila.incompleta = incompletas.includes(String(fila.uid)); });
        },
    }" x-on:consulta-guardada.window="guardada($event.detail)" class="space-y-3" data-informes-estudio>
    <h3 class="font-medium text-gray-900 dark:text-gray-100">Informes de estudio</h3>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        El informe de un estudio realizado en la clínica, en texto libre (hasta {{ \App\Models\InformeEstudio::MAXIMO_TEXTO }} caracteres).
        Hasta {{ \App\Support\Atencion\FormularioConsulta::MAXIMO_INFORMES }} vigentes. Un informe guardado no se borra: se retira, y se puede reponer.
    </p>

    {{-- Solo con JavaScript: indica que la lista viene completa (sin JS no se tocan los informes guardados). --}}
    <template x-if="true"><input type="hidden" name="con_informes" value="1"></template>

    <template x-for="(fila, i) in filas" x-bind:key="fila.uid">
        <div class="space-y-3 rounded-md border p-3"
            x-bind:class="fila.activo ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/40'">
            <input type="hidden" x-bind:name="`informes[${i}][uid]`" x-bind:value="fila.uid">
            <input type="hidden" x-bind:name="`informes[${i}][id]`" x-bind:value="fila.id">
            <input type="hidden" x-bind:name="`informes[${i}][activo]`" x-bind:value="fila.activo ? 1 : 0">
            {{-- El estudio del catálogo (o vacío si se escribe a mano). --}}
            <input type="hidden" x-bind:name="`informes[${i}][estudio_id]`" x-bind:value="fila.eleccion === 'libre' ? '' : fila.eleccion">

            <div class="grid gap-3 sm:grid-cols-2" x-bind:class="fila.activo ? '' : 'opacity-60'">
                <div>
                    <label x-bind:for="`informe_estudio_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">
                        Estudio realizado
                        <span x-show="! fila.activo" class="ms-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">Retirado</span>
                    </label>
                    {{-- Sin name: lo que se envía es el campo oculto de arriba (o el texto escrito a mano). --}}
                    <select x-bind:id="`informe_estudio_${i}`" x-model="fila.eleccion" class="{{ $clases }}">
                        <option value="">Elegir estudio…</option>
                        <template x-for="estudio in estudiosPara(fila)" x-bind:key="estudio.id">
                            <option x-bind:value="estudio.id" x-text="estudio.nombre" x-bind:selected="estudio.id === fila.eleccion"></option>
                        </template>
                        <option value="libre">Otro (no está en el catálogo)</option>
                    </select>
                    <div x-show="fila.eleccion === 'libre'" class="mt-2">
                        <label x-bind:for="`informe_estudio_libre_${i}`" class="sr-only">Nombre del estudio</label>
                        <input type="text" maxlength="{{ \App\Models\Estudio::MAXIMO_NOMBRE }}" placeholder="Nombre del estudio"
                            x-bind:id="`informe_estudio_libre_${i}`" x-bind:name="`informes[${i}][estudio_libre]`" x-bind:disabled="fila.eleccion !== 'libre'"
                            x-model="fila.estudio_libre" class="{{ $clases }}">
                    </div>
                </div>
                <div>
                    <label x-bind:for="`informe_solicitante_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Solicitante (opcional)</label>
                    <input type="text" maxlength="{{ \App\Models\InformeEstudio::MAXIMO_SOLICITANTE }}" placeholder="Por ejemplo, un médico de otra institución"
                        x-bind:id="`informe_solicitante_${i}`" x-bind:name="`informes[${i}][solicitante]`" x-model="fila.solicitante" class="{{ $clases }}">
                </div>
            </div>

            <div x-bind:class="fila.activo ? '' : 'opacity-60'">
                <label x-bind:for="`informe_texto_${i}`" class="block font-medium text-sm text-gray-700 dark:text-gray-300">Informe</label>
                <textarea rows="8" maxlength="{{ \App\Models\InformeEstudio::MAXIMO_TEXTO }}" x-bind:id="`informe_texto_${i}`" x-bind:name="`informes[${i}][texto]`" x-model="fila.texto" class="{{ $clases }}"></textarea>
                <p x-show="fila.incompleta" class="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">{{ \App\Support\Atencion\FormularioConsulta::FILA_INCOMPLETA }}</p>
                <p x-show="fila.autor" x-text="fila.autor" class="mt-1 text-xs text-gray-500 dark:text-gray-400"></p>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                {{-- Guardado: se retira o se repone. Nuevo (todavía sin guardar): se descarta. --}}
                <button type="button" x-show="fila.id" x-on:click="fila.activo = ! fila.activo" x-text="fila.activo ? 'Retirar' : 'Reponer'"
                    class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Retirar</button>
                <button type="button" x-show="! fila.id" x-on:click="descartar(i)"
                    class="text-sm font-medium text-red-600 hover:text-red-900 dark:text-red-400">Descartar</button>
                <a x-show="urlPdf && fila.id && fila.activo" x-bind:href="urlPdf && fila.id ? urlPdf.replace('__ID__', fila.id) : '#'" target="_blank" rel="noopener"
                    data-pdf="informe-estudio" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">PDF del informe</a>
            </div>
        </div>
    </template>

    <p x-show="filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">Sin informes de estudio.</p>

    <button type="button" x-on:click="agregar()" class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
        + Agregar informe de estudio
    </button>

    @if ($errores->isNotEmpty())
        <x-input-error :messages="$errores->all()" />
    @endif
</div>
