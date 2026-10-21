@use('App\Models\AcuerdoReparto')
@use('App\Support\Permisos')

{{-- Acuerdos de reparto (AcuerdoRepartoController::index, docs/reparto-economico.md). --}}
<x-admin.page title="Acuerdos de reparto" :create-route="Permisos::url('admin.acuerdos-reparto.create')" create-label="Nuevo acuerdo">
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        El porcentaje del profesional (general o por procedimiento) y el del dueño de cada equipo, sobre el monto de la prestación.
        La clínica recibe el resto. Un acuerdo no se edita: "Cambiar porcentaje" cierra el vigente y abre otro desde la fecha que
        indique. Los repartos ya hechos no cambian.
    </p>
    <x-admin.listado :action="route('admin.acuerdos-reparto.index')" :busqueda="$busqueda" placeholder="Buscar por profesional, procedimiento o equipo…">
        <x-slot name="filtros">
            <div class="w-48"><x-admin.select name="tipo" label="Tipo" :options="['PROFESIONAL' => 'Profesionales', 'EQUIPO' => 'Equipos']" :value="$filtros['tipo']" nullable /></div>
            <div class="w-48"><x-admin.select name="estado" label="Mostrar" :options="['VIGENTES' => 'Solo vigentes', 'TODOS' => 'Vigentes e históricos']" :value="$filtros['estado']" /></div>
        </x-slot>

        @include('admin.acuerdos-reparto._tabla')
    </x-admin.listado>
</x-admin.page>
