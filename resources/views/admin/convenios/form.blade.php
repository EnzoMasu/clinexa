@use('App\Support\Fecha')

{{-- Alta de un convenio con sus planes, en un solo paso (ConvenioController::create). --}}
<x-admin.page title="Nuevo convenio">
    <x-admin.form :action="route('admin.convenios.store')" :cancel="route('admin.convenios.index')">
        <div>
            <x-admin.select name="responsable_pago_id" label="Aseguradora (responsable de pago)" :options="$responsables" required autofocus />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Si no figura, cárguela antes en Personas y Roles → Responsables de pago.</p>
        </div>

        <x-admin.input name="nombre" label="Nombre del convenio" maxlength="150" required />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-admin.fecha name="vigente_desde" label="Vigente desde" :value="Fecha::hoy()" />
            <div>
                <x-admin.fecha name="vigente_hasta" label="Vigente hasta (opcional)" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Vacío: sin fecha de fin.</p>
            </div>
        </div>

        <div>
            <x-admin.textarea name="planes" label="Planes (uno por línea)" rows="4" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Solo el nombre de cada plan; después puede agregar más desde el convenio.</p>
        </div>
    </x-admin.form>
</x-admin.page>
