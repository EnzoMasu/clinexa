{{--
    Popup de lectura de una consulta (resources/js/popup-consultas.js). Va dentro de un x-data="popupConsultas(...)".
    El contenido lo pone solo la respuesta del servidor (fragmento ya escapado). Requiere $boton (clases).
--}}
<template x-teleport="body">
    <div x-show="abierto" x-cloak class="fixed inset-0 z-50 flex items-stretch justify-center sm:items-center sm:p-6"
        x-on:click.self="cerrar()" style="display: none">
        <div class="fixed inset-0 bg-gray-900/60" aria-hidden="true" x-on:click="cerrar()"></div>
        <div x-ref="dialogo" role="dialog" aria-modal="true" aria-labelledby="popup-consulta-titulo" tabindex="-1"
            x-on:keydown.tab="atraparFoco($event)"
            class="relative flex max-h-full w-full flex-col bg-white shadow-xl focus:outline-none dark:bg-gray-800 sm:max-h-[85vh] sm:max-w-3xl sm:rounded-lg">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <h2 id="popup-consulta-titulo" class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    Consulta <span class="font-normal text-gray-500 dark:text-gray-400" x-text="`${indice + 1} de ${ids.length}`"></span>
                </h2>
                <button type="button" x-on:click="cerrar()" class="rounded p-1 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100" aria-label="Cerrar">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto px-4 py-4" aria-live="polite">
                <p x-show="estado === 'cargando'" role="status" class="text-sm text-gray-500 dark:text-gray-400">Cargando…</p>
                <div x-show="estado === 'error'" role="alert" class="space-y-3 text-sm">
                    <p class="text-red-700 dark:text-red-300">No se pudo cargar la consulta. Intente de nuevo.</p>
                    <button type="button" x-on:click="cargar()" class="{{ $boton }}">Reintentar</button>
                </div>
                <div x-ref="contenido" x-show="estado === 'listo'"></div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                <div class="flex gap-2">
                    <button type="button" x-on:click="anterior()" x-bind:disabled="indice <= 0" class="{{ $boton }}">Anterior</button>
                    <button type="button" x-on:click="siguiente()" x-bind:disabled="indice >= ids.length - 1" class="{{ $boton }}">Siguiente</button>
                </div>
                <div class="flex items-center gap-3">
                    <a x-bind:href="urlDe(urlPagina)" class="text-sm text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Abrir página completa</a>
                    <a x-show="urlEditar" x-bind:href="urlEditar" class="{{ $boton }}">Editar</a>
                </div>
            </div>
        </div>
    </div>
</template>
