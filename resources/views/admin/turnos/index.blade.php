@use('App\Support\Permisos')

<x-admin.page title="Turnos" :create-route="Permisos::url('admin.turnos.create')" create-label="Nuevo turno">
    {{-- Ayuda: atender y preparar ya no se hacen desde esta lista. Cada enlace, solo con su permiso. --}}
    @php($urlConsulta = Permisos::url('admin.atencion.index'))
    @php($urlPreparacion = Permisos::url('admin.preparacion.index'))
    @if ($urlConsulta || $urlPreparacion)
        <p class="px-4 pt-4 text-xs text-gray-500 dark:text-gray-400">
            @if ($urlConsulta)
                Para atender un turno de hoy, o marcar que el paciente no se presentó, use <a href="{{ $urlConsulta }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Consulta</a>.
            @endif
            @if ($urlPreparacion)
                Para cargar la anamnesis y los signos vitales antes de la consulta, use <a href="{{ $urlPreparacion }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Preparación</a>.
            @endif
        </p>
    @endif
    <x-admin.listado :action="route('admin.turnos.index')" :busqueda="$busqueda" placeholder="Buscar por paciente, profesional o fecha (dd/mm/aaaa)…">
        @include('admin.turnos._tabla')
    </x-admin.listado>
</x-admin.page>
