{{--
    Botón y calendario desplegable de un campo de fecha. Va dentro de un x-data="campoFecha(...)"
    (resources/js/campo-fecha.js), junto al input x-ref="entrada".
--}}
<button type="button" x-on:click="abierto ? cerrar() : abrir()" aria-label="Abrir calendario"
    class="absolute inset-y-0 end-0 flex items-center px-3 text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400">
    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75Z" clip-rule="evenodd" />
    </svg>
</button>

<div x-show="abierto" x-on:click.outside="cerrar()" x-on:keydown.escape.window="cerrar()" x-transition.opacity style="display: none"
    class="absolute z-20 mt-1 w-72 rounded-md border border-gray-200 bg-white p-3 shadow-lg dark:border-gray-700 dark:bg-gray-800" role="dialog" aria-label="Calendario">
    <div class="mb-2 flex items-center gap-2">
        <button type="button" x-on:click="moverMes(-1)" aria-label="Mes anterior" class="rounded px-2 py-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">&lsaquo;</button>
        <select x-model.number="mes" aria-label="Mes" class="flex-1 rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            <template x-for="(nombre, i) in meses" x-bind:key="i">
                <option x-bind:value="i" x-text="nombre" x-bind:selected="i === mes"></option>
            </template>
        </select>
        <select x-model.number="anio" aria-label="Año" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            <template x-for="a in anios" x-bind:key="a">
                <option x-bind:value="a" x-text="a" x-bind:selected="a === anio"></option>
            </template>
        </select>
        <button type="button" x-on:click="moverMes(1)" aria-label="Mes siguiente" class="rounded px-2 py-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">&rsaquo;</button>
    </div>

    <div class="grid grid-cols-7 gap-1 text-center text-xs">
        <template x-for="nombre in nombresDias" x-bind:key="nombre">
            <div class="py-1 font-medium text-gray-500 dark:text-gray-400" x-text="nombre"></div>
        </template>
        <template x-for="dia in dias" x-bind:key="dia.clave">
            <button type="button" x-on:click="elegir(dia)" x-text="dia.numero" x-bind:disabled="dia.deshabilitado"
                class="rounded py-1 text-sm disabled:cursor-not-allowed disabled:opacity-30"
                x-bind:class="{
                    'bg-indigo-600 text-white': dia.elegido,
                    'ring-1 ring-indigo-400': dia.hoy && ! dia.elegido,
                    'text-gray-900 hover:bg-indigo-50 dark:text-gray-100 dark:hover:bg-gray-700': dia.delMes && ! dia.elegido,
                    'text-gray-400 hover:bg-gray-50 dark:text-gray-500 dark:hover:bg-gray-700': ! dia.delMes && ! dia.elegido,
                }"></button>
        </template>
    </div>
</div>
