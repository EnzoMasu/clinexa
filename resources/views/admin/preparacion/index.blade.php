{{--
    Preparación (PreparacionController::index): turnos de hoy de todos los profesionales, con filtro por
    profesional y búsqueda en vivo. Solo datos de agenda. La lista se actualiza sola cada 30 s, con los
    filtros que estén puestos (la URL los refleja).
--}}
<x-admin.page title="Preparación">
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
