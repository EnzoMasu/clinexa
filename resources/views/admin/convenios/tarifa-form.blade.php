@use('App\Support\Fecha')

{{-- Un solo formulario para el alta y el cambio de la tarifa de un procedimiento en un convenio (ConvenioController::crearTarifa). --}}
<x-admin.page title="Tarifa del convenio">
    <x-admin.form :action="route('admin.convenios.tarifas.store')" :cancel="route('admin.convenios.tarifas')">
        <x-admin.select name="convenio_id" label="Convenio" :options="$convenios" :value="$convenioId" required autofocus />
        <x-admin.select name="procedimiento_id" label="Procedimiento" :options="$procedimientos" :value="$procedimientoId" required />

        <div>
            <x-admin.input name="monto" label="Monto en guaraníes (₲)" inputmode="numeric" maxlength="17" autocomplete="off" required />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Sin decimales, por ejemplo 150000 o 150.000.</p>
        </div>

        <div>
            <x-admin.fecha name="vigente_desde" label="Vigente desde" :value="Fecha::hoy()" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Si el procedimiento ya tiene tarifa en el convenio, la vigente se cierra el día anterior y esta la reemplaza (desde hoy o una
                fecha posterior). Las prestaciones ya creadas no cambian.
            </p>
        </div>
    </x-admin.form>
</x-admin.page>
