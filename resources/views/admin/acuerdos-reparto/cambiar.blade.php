@use('App\Support\Fecha')

{{-- "Cambiar porcentaje" (AcuerdoRepartoController::cambiar): no edita; cierra el vigente y abre otro. --}}
<x-admin.page title="Cambiar porcentaje">
    <dl class="grid max-w-xl grid-cols-1 gap-x-6 gap-y-2 p-6 pb-0 text-sm sm:grid-cols-2">
        <div><dt class="text-gray-500 dark:text-gray-400">Beneficiario</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $acuerdo->beneficiarioTexto() }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Alcance</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $acuerdo->alcanceTexto() }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Porcentaje vigente</dt><dd class="font-medium text-gray-900 dark:text-gray-100 tabular-nums">{{ $acuerdo->porcentajeTexto() }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Vigencia</dt><dd class="font-medium text-gray-900 dark:text-gray-100">{{ $acuerdo->vigenciaTexto() }}</dd></div>
    </dl>

    <x-admin.form :action="route('admin.acuerdos-reparto.guardar-cambio', $acuerdo)" :cancel="route('admin.acuerdos-reparto.index')" submit-label="Cambiar porcentaje">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            El acuerdo vigente se cierra el día anterior a la fecha que indique y queda en el historial. El nuevo puede empezar el
            {{ Fecha::mostrar($minimo) }} o después. Los repartos ya hechos no cambian.
        </p>

        <div>
            <x-admin.input name="porcentaje" label="Porcentaje nuevo (%)" inputmode="decimal" maxlength="6" autocomplete="off" required autofocus />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">De 0 a 100, con hasta dos decimales.</p>
        </div>

        <x-admin.fecha name="vigente_desde" label="Vigente desde" :value="$minimo" />
    </x-admin.form>
</x-admin.page>
