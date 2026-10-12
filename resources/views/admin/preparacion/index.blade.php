{{--
    Preparación (PreparacionController::index): turnos de hoy de todos los profesionales, con filtro por
    profesional y búsqueda en vivo. Solo datos de agenda. La lista se actualiza sola cada 30 s, con los
    filtros que estén puestos (la URL los refleja).
--}}
<x-admin.page title="Triaje">
    @unless ($esDeSalud)
        {{-- Ve la lista (VER), pero no carga el triaje: no es personal de salud (ConsultaPolicy::cargarTriaje). --}}
        <p role="status" class="m-6 mb-0 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            {{ \App\Policies\ConsultaPolicy::SOLO_PERSONAL_SALUD }} Puede ver la lista, pero no iniciar ni continuar un triaje.
        </p>
    @endunless
    <div x-data="refrescoPeriodico()">
        <x-admin.listado :action="route('admin.preparacion.index')" :busqueda="$busqueda" placeholder="Buscar por paciente, ficha o profesional…">
            <x-slot name="filtros">
                <div class="w-64">
                    <x-admin.select name="profesional" label="Profesional" :options="$profesionales" :value="$profesional" nullable />
                </div>
            </x-slot>

            @include('admin.preparacion._tabla')
        </x-admin.listado>
    </div>
</x-admin.page>
