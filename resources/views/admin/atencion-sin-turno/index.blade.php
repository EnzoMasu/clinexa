@use('App\Support\Permisos')

<x-admin.page title="Atención sin turno">
    {{-- A) Iniciar una atención: buscar al paciente y abrir el formulario de la consulta sin turno. --}}
    <section class="space-y-3 border-b border-gray-200 p-6 dark:border-gray-700">
        <h3 class="font-medium text-gray-900 dark:text-gray-100">Iniciar atención</h3>

        @if ($puedeAtender)
            {{--
                El buscador del alta de turnos (x-admin.selector-persona), con un endpoint que devuelve el id de
                la historia clínica. "Atender" lleva al mismo formulario que "Atender sin turno" de la historia:
                la URL se completa con ese id (no lleva datos clínicos).
            --}}
            <div class="max-w-2xl space-y-3"
                x-data="{ historia: null, plantilla: {{ Js::from(route('admin.consultas.create', ['historiaClinica' => '__ID__'])) }} }"
                x-on:elegido="historia = $event.detail.id">
                <x-admin.selector-persona name="paciente_atencion" label="Paciente" :url="route('admin.atencion-sin-turno.pacientes')"
                    :ofrecer-alta="false" sin-resultados="No hay pacientes activos con esa búsqueda."
                    ayuda="Solo aparecen pacientes activos. Busque por nombre, documento o número de ficha." />

                <a x-show="historia" style="display: none" x-bind:href="historia ? plantilla.replace('__ID__', historia) : '#'"
                    class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white">Atender</a>

                @php
                    // Cada enlace, solo con el permiso de su módulo: CREAR para dar de alta; EDITAR (y VER, que
                    // pide el listado) para reactivar un paciente, que se hace editando su estado en Pacientes.
                    $urlPersona = Permisos::url('admin.personas.create');
                    $urlPaciente = Permisos::url('admin.pacientes.create');
                    $urlReactivar = auth()->user()->tienePermiso('PACIENTES', 'EDITAR') ? Permisos::url('admin.pacientes.index') : null;
                @endphp
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Si el paciente no aparece, puede que todavía no esté registrado o que esté inactivo. Un paciente nuevo se da de alta
                    primero en Personas y en Pacientes; uno inactivo se reactiva desde Pacientes.
                    @if ($urlPersona)
                        <a href="{{ $urlPersona }}" class="font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Nueva persona</a>
                    @endif
                    @if ($urlPaciente)
                        <a href="{{ $urlPaciente }}" class="ms-2 font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Nuevo paciente</a>
                    @endif
                    @if ($urlReactivar)
                        <a href="{{ $urlReactivar }}" class="ms-2 font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">Ir a Pacientes</a>
                    @endif
                </p>
            </div>
        @else
            <p role="status" class="rounded-md bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                Solo un profesional activo con permiso para crear consultas puede iniciar una atención.
            </p>
        @endif
    </section>

    {{-- B) Atenciones sin turno del día elegido (hoy por defecto, en hora de Paraguay). --}}
    <section>
        <h3 class="px-4 pt-4 font-medium text-gray-900 dark:text-gray-100">Atenciones sin turno</h3>
        <x-admin.listado :action="route('admin.atencion-sin-turno.index')" :busqueda="$busqueda" placeholder="Buscar por paciente, ficha o profesional…">
            <x-slot name="filtros">
                <div class="w-40">
                    <x-admin.fecha name="fecha" label="Día" :value="$fecha" />
                </div>
            </x-slot>

            @include('admin.atencion-sin-turno._tabla')
        </x-admin.listado>
    </section>
</x-admin.page>
