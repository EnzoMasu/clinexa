{{--
    Estado del autoguardado (dentro de x-data="consultaAutoguardado(...)"): "Guardando…", "Guardado a las
    HH:MM", "No se pudo guardar. Reintentar" y "Guardar ahora". El cartel fijo (409 o sesión vencida) lo
    muestra _cartel-guardado.
--}}
<span class="flex items-center gap-3 text-xs" role="status" aria-live="polite">
    <span x-show="estado === 'guardando'" class="text-gray-600 dark:text-gray-400">Guardando…</span>
    <span x-show="estado === 'pendiente'" class="text-gray-600 dark:text-gray-400">Cambios sin guardar</span>
    <span x-show="estado === 'guardado' && horaGuardado" class="text-green-700 dark:text-green-300" x-text="`Guardado a las ${horaGuardado}`"></span>
    <span x-show="estado === 'error' && ! cartel" class="text-red-700 dark:text-red-300">
        No se pudo guardar. <button type="button" x-on:click="guardarAhora()" class="font-semibold underline">Reintentar</button>
    </span>
    <button type="button" x-on:click="guardarAhora()" x-bind:disabled="!! cartel || enVuelo"
        class="rounded-md border border-gray-300 px-2 py-1 font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">Guardar ahora</button>
</span>
