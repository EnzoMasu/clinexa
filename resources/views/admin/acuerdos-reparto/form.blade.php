@use('App\Models\AcuerdoReparto')
@use('App\Support\Fecha')

{{-- Alta de un acuerdo de reparto (AcuerdoRepartoController::create). Si ya hay uno para esa clave, se usa "Cambiar porcentaje". --}}
<x-admin.page title="Nuevo acuerdo">
    <x-admin.form :action="route('admin.acuerdos-reparto.store')" :cancel="route('admin.acuerdos-reparto.index')">
        {{-- Sin JavaScript se ven los dos grupos de campos; el servidor usa solo los del tipo elegido. --}}
        <div x-data="{ tipo: @js($tipo) }" class="space-y-6">
            <x-admin.select name="tipo" label="¿Qué acuerdo desea cargar?" :options="AcuerdoReparto::TIPOS" :value="$tipo" x-model="tipo" autofocus />

            <div x-show="tipo === '{{ AcuerdoReparto::PROFESIONAL }}'" class="space-y-6">
                <x-admin.select name="profesional_id" label="Profesional" :options="$profesionales" />

                <div>
                    <x-admin.select name="procedimiento_id" label="Procedimiento" :options="$procedimientos" nullable />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Sin procedimiento: el acuerdo general del profesional. Uno para un procedimiento lo reemplaza en ese procedimiento.
                    </p>
                </div>
            </div>

            <div x-show="tipo === '{{ AcuerdoReparto::EQUIPO }}'">
                <x-admin.select name="equipo_id" label="Equipo" :options="$equipos" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">El porcentaje es para el dueño del equipo, en las prestaciones de los procedimientos que lo usan.</p>
            </div>
        </div>

        <div>
            <x-admin.input name="porcentaje" label="Porcentaje (%)" inputmode="decimal" maxlength="6" autocomplete="off" required />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">De 0 a 100, con hasta dos decimales (por ejemplo, 35 o 35,5). Sobre el monto de la prestación.</p>
        </div>

        <x-admin.fecha name="vigente_desde" label="Vigente desde" :value="Fecha::hoy()" />
    </x-admin.form>
</x-admin.page>
