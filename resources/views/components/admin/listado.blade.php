{{--
    Buscador + resultados de un listado de /admin, con búsqueda y paginación en vivo (resources/js/listado-en-vivo.js).
    El slot es el partial _tabla del listado. Sin JavaScript funciona como un formulario GET común con botón "Buscar".
    "filtros" (opcional): campos extra del formulario (selects, fechas) que también filtran en vivo al cambiar.
--}}
@props(['action', 'busqueda' => '', 'placeholder' => 'Buscar…'])

<div x-data="listadoEnVivo" x-on:fecha-cambiada="alCambiarFecha($event)">
    <form method="GET" action="{{ $action }}" role="search" x-ref="formulario" x-on:submit.prevent="buscar()" x-on:change="buscar()"
        class="flex flex-wrap items-end gap-3 p-4 border-b border-gray-200 dark:border-gray-700">
        <x-text-input name="q" type="search" :value="$busqueda" :placeholder="$placeholder" aria-label="{{ $placeholder }}"
            x-ref="q" x-on:input.debounce.350ms="buscar()" autocomplete="off" class="block w-full max-w-md" />

        {{-- Respaldo sin JavaScript: con Alpine activo se oculta y la búsqueda es automática. --}}
        @isset($filtros)
            {{ $filtros }}
        @endisset

        <x-secondary-button type="submit" x-show="false">Buscar</x-secondary-button>

        <span x-show="cargando" style="display: none" class="text-sm text-gray-500 dark:text-gray-400" role="status">Buscando…</span>
    </form>

    <div x-ref="resultados" x-on:click="paginar($event)" x-bind:aria-busy="cargando.toString()"
        x-bind:class="{ 'opacity-50 transition-opacity': cargando }">
        {{ $slot }}
    </div>
</div>
