{{--
    Elegir una persona existente (resources/js/selector-persona.js), en el alta de Usuarios y de los
    roles de negocio. "url" es el endpoint personas-disponibles de la sección, que ya filtra las
    personas que se pueden elegir; "ayuda" explica ese filtro al usuario. "conEmail" debe coincidir
    con lo que devuelve el endpoint, para que la persona recordada tras un error se vea igual.

    También sirve para elegir un registro de un rol (paciente, profesional): "url" devuelve sus ids,
    "inicial" (['id' => ..., 'texto' => ...]) es lo elegido de antes y "ofrecerAlta" en false
    oculta el enlace para cargar una persona nueva.
--}}
@props([
    'url',
    'name' => 'persona_id',
    'label' => 'Persona',
    'conEmail' => false,
    'ayuda' => 'Solo aparecen personas activas que todavía no tienen este rol. Nombre y documento se toman de la persona.',
    'inicial' => null,
    'ofrecerAlta' => true,
    'sinResultados' => 'No hay personas disponibles con esa búsqueda.',
])

@php
    use App\Models\Persona;
    use App\Support\BuscadorPersonas;
    use App\Support\Permisos;

    // Tras un error de validación se conserva la persona que se había elegido (o lo que indique "inicial").
    if ($inicial === null && old($name) && ($anterior = Persona::with('tipoDocumento')->find(old($name)))) {
        $inicial = ['id' => $anterior->id, 'texto' => BuscadorPersonas::texto($anterior, $conEmail)];
    }
    $urlNuevaPersona = $ofrecerAlta ? Permisos::url('admin.personas.create') : null;
    $minimo = BuscadorPersonas::MINIMO;
@endphp

<div x-data="selectorPersona({ url: @js($url), inicial: @js($inicial), minimo: {{ $minimo }}, campo: @js($name) })" class="space-y-2">
    <x-input-label :for="$name.'_buscar'" :value="$label" />
    <input type="hidden" name="{{ $name }}" x-bind:value="elegida ? elegida.id : ''" value="{{ $inicial['id'] ?? '' }}">

    {{-- Persona elegida --}}
    <div x-show="elegida" style="{{ $inicial ? '' : 'display: none' }}"
        class="flex items-center justify-between gap-3 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm dark:border-indigo-800 dark:bg-indigo-900/30">
        <span class="font-medium text-gray-900 dark:text-gray-100" x-text="elegida?.texto">{{ $inicial['texto'] ?? '' }}</span>
        <button type="button" x-on:click="cambiar()" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">Cambiar</button>
    </div>

    {{-- Búsqueda --}}
    <div x-show="! elegida" style="{{ $inicial ? 'display: none' : '' }}">
        <x-text-input :id="$name.'_buscar'" type="search" x-ref="busqueda" x-model="q" x-on:input.debounce.350ms="buscar()"
            placeholder="Buscar por nombre o documento…" autocomplete="off" class="block w-full"
            aria-describedby="{{ $name }}_ayuda" />
        <p id="{{ $name }}_ayuda" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ $ayuda }} Se muestran hasta {{ BuscadorPersonas::LIMITE }} resultados: si no aparece, afine la búsqueda.
        </p>

        <ul class="mt-2 max-h-64 overflow-y-auto rounded-md border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700"
            x-bind:aria-busy="cargando.toString()">
            <template x-for="persona in resultados" x-bind:key="persona.id">
                <li>
                    <button type="button" x-on:click="elegir(persona)" x-text="persona.texto"
                        class="block w-full px-3 py-2 text-left text-sm text-gray-800 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:bg-gray-700"></button>
                </li>
            </template>
            {{-- Visible de entrada: el formulario no busca hasta que se escriba. --}}
            <li x-show="faltanCaracteres" class="px-3 py-3 text-sm text-gray-500 dark:text-gray-400">
                Escriba al menos {{ $minimo }} caracteres del nombre o del documento para buscar.
            </li>
            <li x-show="buscado && ! cargando && ! faltanCaracteres && resultados.length === 0" style="display: none" class="px-3 py-3 text-sm text-gray-600 dark:text-gray-400">
                {{ $sinResultados }}
                @if ($urlNuevaPersona)
                    <a href="{{ $urlNuevaPersona }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Cargar una persona nueva</a>
                @endif
            </li>
            <li x-show="cargando" style="display: none" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400" role="status">Buscando…</li>
        </ul>
    </div>

    <noscript><p class="text-sm text-red-600">Para elegir la persona se necesita JavaScript habilitado.</p></noscript>
    <x-input-error :messages="$errors->get($name)" />
</div>
