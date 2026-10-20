@use('App\Support\Fecha')

{{-- "Cambiar tarifa" (TarifaController::cambiar): no edita; cierra la vigente y abre otra. --}}
<x-admin.page title="Cambiar tarifa">
    <dl class="grid max-w-xl grid-cols-1 gap-x-6 gap-y-2 p-6 pb-0 text-sm sm:grid-cols-2">
        <div><dt class="text-gray-500 dark:text-gray-400">Procedimiento</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $tarifa->procedimiento->nombre }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Profesional</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $tarifa->profesionalTexto() }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Monto vigente</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $tarifa->montoTexto() }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Vigencia</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $tarifa->vigenciaTexto() }}</dd></div>
    </dl>

    <x-admin.form :action="route('admin.tarifas.guardar-cambio', $tarifa)" :cancel="route('admin.tarifas.index')" submit-label="Cambiar tarifa">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            La tarifa vigente se cierra el día anterior a la fecha que indique y queda en el historial. La nueva puede empezar el
            {{ Fecha::mostrar($minimo) }} o después. Las prestaciones ya tarifadas conservan su monto.
        </p>

        <div>
            <x-admin.input name="monto" label="Monto nuevo en guaraníes (₲)" inputmode="numeric" maxlength="17" autocomplete="off" required autofocus />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Sin decimales, por ejemplo 150000 o 150.000.</p>
        </div>

        <x-admin.fecha name="vigente_desde" label="Vigente desde" :value="$minimo" />
    </x-admin.form>
</x-admin.page>
