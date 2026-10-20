@use('App\Support\Fecha')

{{-- Alta de una tarifa (TarifaController::create). Si ya hay una para ese procedimiento y profesional, se usa "Cambiar tarifa". --}}
<x-admin.page title="Nueva tarifa">
    <x-admin.form :action="route('admin.tarifas.store')" :cancel="route('admin.tarifas.index')">
        <x-admin.select name="procedimiento_id" label="Procedimiento" :options="$procedimientos" required autofocus />

        <div>
            <x-admin.select name="profesional_id" label="Profesional" :options="$profesionales" nullable />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Sin profesional: tarifa general del procedimiento, para los profesionales que no tienen una propia.
            </p>
        </div>

        <div>
            <x-admin.input name="monto" label="Monto en guaraníes (₲)" inputmode="numeric" maxlength="17" autocomplete="off" required />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Sin decimales, por ejemplo 150000 o 150.000.</p>
        </div>

        <x-admin.fecha name="vigente_desde" label="Vigente desde" :value="Fecha::hoy()" />
    </x-admin.form>
</x-admin.page>
