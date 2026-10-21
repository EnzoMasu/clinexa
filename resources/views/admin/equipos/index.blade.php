@use('App\Support\Permisos')

{{-- Equipos (EquipoController::index, docs/reparto-economico.md): su dueño cobra el porcentaje de su acuerdo en los procedimientos que lo usan. --}}
<x-admin.page title="Equipos" :create-route="Permisos::url('admin.equipos.create')" create-label="Nuevo equipo">
    <p class="px-4 pt-4 text-sm text-gray-600 dark:text-gray-400">
        Cada procedimiento usa como máximo un equipo activo. En las prestaciones de ese procedimiento, el dueño del equipo recibe el
        porcentaje de su acuerdo de reparto. La reserva de equipos en la agenda todavía no está disponible.
    </p>
    <x-admin.listado :action="route('admin.equipos.index')" :busqueda="$busqueda" placeholder="Buscar por nombre, tipo o dueño…">
        @include('admin.equipos._tabla')
    </x-admin.listado>
</x-admin.page>
