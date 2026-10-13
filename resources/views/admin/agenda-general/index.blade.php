{{--
    Agenda general (AgendaGeneralController::index): los turnos de un día de todos los profesionales, para que recepción
    busque disponibilidad. Filtros en vivo (y con el botón Buscar sin JavaScript). Se actualiza sola cada 30 s con los filtros
    puestos. Sin contenido clínico.
--}}
<x-admin.page title="Agenda general">
    <div x-data="refrescoPeriodico()">
        <x-admin.listado :action="route('admin.agenda-general.index')" :busqueda="$busqueda" placeholder="Buscar por paciente o ficha…">
            <x-slot name="filtros">
                <div class="w-40"><x-admin.fecha name="fecha" label="Fecha" :value="$filtros['fecha']" /></div>
                <div class="w-52"><x-admin.select name="profesional" label="Profesional" :options="$opcionesProfesional" :value="$filtros['profesional']" nullable /></div>
                <div class="w-48"><x-admin.select name="especialidad" label="Especialidad" :options="$opcionesEspecialidad" :value="$filtros['especialidad']" nullable /></div>
                <div class="w-40"><x-admin.select name="estado" label="Estado" :options="$opcionesEstado" :value="$filtros['estado']" nullable /></div>
                <div class="w-40"><x-admin.select name="tipo" label="Tipo" :options="$opcionesTipo" :value="$filtros['tipo']" nullable /></div>
            </x-slot>

            @include('admin.agenda-general._tabla')
        </x-admin.listado>
    </div>
</x-admin.page>
