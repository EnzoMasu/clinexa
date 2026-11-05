{{--
    Elegir una persona existente (resources/js/selector-persona.js), en el alta de Usuarios y de los
    roles de negocio. "url" es el endpoint personas-disponibles de la sección, que ya filtra las
    personas que se pueden elegir; "ayuda" explica ese filtro al usuario. "conEmail" debe coincidir
    con lo que devuelve el endpoint, para que la persona recordada tras un error se vea igual.

    También sirve para elegir un registro de un rol (paciente, profesional): "url" devuelve sus ids,
    "inicial" (['id' => ..., 'texto' => ...]) es lo elegido de antes y "ofrecerAlta" en false
    oculta el enlace para cargar una persona nueva.

    "restaurar" en false: no recupera la persona de old() (quien llama la pasa ya verificada en "inicial").
    "pista": placeholder de la caja de búsqueda (por defecto, el mínimo de caracteres para buscar); p. ej.
    Proveedores pasa la suya para la búsqueda por número exacto. El slot "altaRapida" se muestra cuando no
    hay resultados, con "q" (lo buscado) a mano; una persona creada desde afuera se elige con el evento de
    ventana "persona-creada" ({ campo, persona: { id, texto } }).

    Alta rápida: "ruta-alta-rapida" (nombre de una ruta POST) ofrece "Crear" cuando no hay resultados, solo a quien
    tiene el permiso de esa ruta, y abre el formulario completo de Personas en una ventana emergente
    (x-admin.alta-rapida-persona), sin salir de la pantalla. "parametros-alta-rapida": parámetros de esa ruta;
    "tipos-alta-rapida": tipos de persona que se ofrecen; "rol-alta-rapida": si la ruta crea también el registro
    (p. ej. 'paciente'); "destino-alta-rapida": cómo se nombra el formulario en el texto de la ventana.
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
    'restaurar' => true,
    'pista' => null,
    'rutaAltaRapida' => null,
    'parametrosAltaRapida' => [],
    'tiposAltaRapida' => ['FISICA', 'JURIDICA'],
    'rolAltaRapida' => null,
    'destinoAltaRapida' => 'este formulario',
])

@php
    use App\Models\Persona;
    use App\Support\BuscadorPersonas;
    use App\Support\Permisos;

    // Tras un error de validación se conserva la persona que se había elegido (o lo que indique "inicial").
    if ($restaurar && $inicial === null && old($name) && ($anterior = Persona::with('tipoDocumento')->find(old($name)))) {
        $inicial = ['id' => $anterior->id, 'texto' => BuscadorPersonas::texto($anterior, $conEmail)];
    }
    // Con alta rápida disponible no se manda a otra pantalla.
    $altaRapida = $rutaAltaRapida && Permisos::puedeRuta($rutaAltaRapida);
    $urlNuevaPersona = $ofrecerAlta && ! $altaRapida ? Permisos::url('admin.personas.create') : null;
    $minimo = BuscadorPersonas::MINIMO;
    // La pista va como placeholder de la caja de búsqueda (por defecto, la misma en todos los buscadores de personas).
    $pista ??= "Escriba al menos {$minimo} caracteres del nombre o del documento para buscar.";
@endphp

<div x-data="selectorPersona({ url: @js($url), inicial: @js($inicial), minimo: {{ $minimo }}, campo: @js($name) })" class="space-y-2"
    x-on:persona-creada.window="if ($event.detail.campo === campo) elegir($event.detail.persona)">
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
            placeholder="{{ $pista }}" title="{{ $pista }}" autocomplete="off" class="block w-full"
            aria-describedby="{{ $name }}_ayuda" />
        <p id="{{ $name }}_ayuda" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ $ayuda }} Se muestran hasta {{ BuscadorPersonas::LIMITE }} resultados: si no aparece, afine la búsqueda.
        </p>

        {{-- Sin nada que mostrar (todavía no se escribió el mínimo) la lista no aparece: el mínimo lo dice el placeholder. --}}
        <ul x-show="! faltanCaracteres || resultados.length > 0" style="display: none" class="mt-2 max-h-64 overflow-y-auto rounded-md border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700"
            x-bind:aria-busy="cargando.toString()">
            <template x-for="persona in resultados" x-bind:key="persona.id">
                <li>
                    <button type="button" x-on:click="elegir(persona)" x-text="persona.texto"
                        class="block w-full px-3 py-2 text-left text-sm text-gray-800 hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:bg-gray-700"></button>
                </li>
            </template>
            <li x-show="buscado && ! cargando && ! faltanCaracteres && resultados.length === 0" style="display: none" class="px-3 py-3 text-sm text-gray-600 dark:text-gray-400">
                {{ $sinResultados }}
                @if ($urlNuevaPersona)
                    <a href="{{ $urlNuevaPersona }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Cargar una persona nueva</a>
                @endif
                @if ($altaRapida)
                    <button type="button" x-on:click="$dispatch('abrir-alta-rapida-persona', { campo, numero: q.trim() })"
                        class="ml-1 font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Crear</button>
                @endif
            </li>
            <li x-show="cargando" style="display: none" class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400" role="status">Buscando…</li>
        </ul>
    </div>

    <noscript><p class="text-sm text-red-600">Para elegir la persona se necesita JavaScript habilitado.</p></noscript>
    <x-input-error :messages="$errors->get($name)" />
</div>

@if ($altaRapida)
    @push('modales')
        <x-admin.alta-rapida-persona :url="route($rutaAltaRapida, $parametrosAltaRapida)" :campo="$name" :tipos="$tiposAltaRapida" :con-rol="$rolAltaRapida" :destino="$destinoAltaRapida" />
    @endpush
@endif
